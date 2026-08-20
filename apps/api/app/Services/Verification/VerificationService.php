<?php

namespace App\Services\Verification;

use App\Domain\Claims\Models\Claim;
use App\Domain\Decisions\Models\Decision;
use App\Domain\Identity\Models\User;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Reviews\Models\Review;
use App\Domain\Shared\Enums\AuditEventType;
use App\Domain\Shared\Enums\ClaimStatus;
use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Shared\Enums\ReviewStatus;
use App\Domain\Shared\Enums\TraceEventType;
use App\Domain\Shared\Enums\VerificationStatus;
use App\Domain\Shared\Enums\WorkOrderStatus;
use App\Domain\Verification\Models\VerificationRun;
use App\Services\Agent\AgentContextBuilder;
use App\Services\Agent\AgentGateway;
use App\Services\Agent\AgentResult;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The product-core side of the verification loop.
 *
 *   Claim -> policy -> agent runtime -> evidence -> deterministic guard
 *         -> decision -> review/closure -> audit
 *
 * Everything that must survive is written here, in PostgreSQL. The agent
 * runtime holds no durable state.
 */
class VerificationService
{
    public function __construct(
        private readonly AgentGateway $agent,
        private readonly AgentContextBuilder $contextBuilder,
        private readonly EvidenceRecorder $evidenceRecorder,
        private readonly TraceRecorder $traceRecorder,
        private readonly DecisionGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function verify(Claim $claim, ?User $actor = null, array $options = []): VerificationRun
    {
        if (! $claim->status->canStartVerification()) {
            throw new RuntimeException("Claim {$claim->reference} cannot be verified in state {$claim->status->value}.");
        }

        // Load everything the loop touches up front: the agent bundle, the
        // guard and the outcome propagation all read these relations.
        $claim->loadMissing(['workOrder.policy', 'workOrder.device', 'user', 'device', 'organization']);

        $policy = $this->resolvePolicy($claim);
        $run = $this->openRun($claim, $policy, $actor);

        // Every audit event in this run carries the claim and work order it
        // belongs to. Without it the log is a wall of UUIDs: one verification
        // writes a dozen rows, and only the first one said which claim it was
        // about, so nobody could follow a case through it.
        $trail = [
            'claim_reference' => $claim->reference,
            'work_order_reference' => $claim->workOrder?->reference,
        ];

        $this->audit->log(AuditEventType::VERIFICATION_STARTED, $run, [
            'claim_reference' => $claim->reference,
            'policy' => $policy?->key,
            'budget' => $run->budget_max_tool_calls,
        ], $actor);

        // ── Call the agent runtime (single bounded round trip) ────────────
        $bundle = $this->contextBuilder->build($run, $claim, $options);
        $startedAt = microtime(true);
        $result = $this->agent->verify($bundle);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->audit->logAgent(AuditEventType::AGENT_INVOKED, $run, $trail + [
            'status' => $result->status,
            'tool_calls_used' => $result->toolCallsUsed,
            'planner_mode' => $result->plannerMode,
            'duration_ms' => $result->durationMs ?: $elapsedMs,
        ], $run->organization_id);

        return DB::transaction(function () use ($run, $claim, $policy, $result, $elapsedMs, $actor) {
            // 1. Trace first: even a failed run must be explainable.
            $run->loadMissing('policy');
            $this->traceRecorder->record($run, $result->trace);

            // 2. Evidence — validated, normalised, immutable.
            $evidence = $this->evidenceRecorder->record($run, $result->evidence);

            foreach ($evidence as $item) {
                $this->audit->logAgent(AuditEventType::EVIDENCE_RECEIVED, $item, $trail + [
                    'type' => $item->type->value,
                    'status' => $item->status->value,
                    'source' => $item->source->value,
                    'request_id' => $item->request_id,
                ], $run->organization_id);
            }

            if ($evidence->contains(fn ($e) => $e->status->isNegative())) {
                $this->audit->logAgent(AuditEventType::EVIDENCE_CONFLICT, $run, $trail + [
                    'conflicting' => $evidence->filter(fn ($e) => $e->status->isNegative())->pluck('type')->map->value->all(),
                ], $run->organization_id);
            }

            if ($result->escalated) {
                $this->audit->logAgent(AuditEventType::ESCALATION, $run, $trail + [
                    'tool_calls_used' => $result->toolCallsUsed,
                ], $run->organization_id);
            }

            // 3. Deterministic guard. The agent proposes; policy disposes.
            $guarded = $this->guard->apply($evidence, $policy, $result->decision);

            if (! $result->ok) {
                $this->traceRecorder->append(
                    $run,
                    TraceEventType::AGENT_FAILED,
                    'Automated verification could not be completed',
                    ['reason' => $result->failureReason]
                );

                $this->audit->logSystem(AuditEventType::AGENT_FAILURE, $run, $trail + [
                    'reason' => $result->failureReason,
                ], $run->organization_id);
            }

            if ($guarded->guardApplied) {
                $this->traceRecorder->append(
                    $run,
                    TraceEventType::GUARD_APPLIED,
                    'Deterministic policy layer resolved '.$guarded->state->value,
                    ['reason' => $guarded->guardReason]
                );

                $this->audit->logSystem(AuditEventType::DECISION_GUARD_APPLIED, $run, $trail + [
                    'proposed' => $guarded->proposedState->value,
                    'final' => $guarded->state->value,
                ], $run->organization_id);
            }

            // 4. Persist the decision, superseding any previous one.
            $decision = $this->recordDecision($run, $claim, $guarded, $result);

            // 5. Close the run.
            $run->forceFill([
                'status' => $result->ok ? VerificationStatus::COMPLETED : VerificationStatus::FAILED,
                'tool_calls_used' => min($result->toolCallsUsed, (int) $run->budget_max_tool_calls),
                'duration_ms' => $result->durationMs ?: $elapsedMs,
                'escalated' => $result->escalated,
                'used_demo_fallback' => $result->usedDemoFallback || $guarded->simulated,
                'agent_version' => $result->agentVersion,
                'planner_mode' => $result->plannerMode,
                'llm_provider' => $result->llmProvider,
                'llm_model' => $result->llmModel,
                'evidence_plan' => $result->evidencePlan,
                'failure_reason' => $result->failureReason,
                'completed_at' => now(),
            ])->save();

            // 6. Propagate to the operational objects.
            $this->applyOutcome($claim, $decision, $policy, $guarded);

            return $run->fresh(['evidence', 'traceEvents', 'decision', 'policy', 'claim.workOrder']);
        });
    }

    private function openRun(Claim $claim, ?VerificationPolicy $policy, ?User $actor): VerificationRun
    {
        $claim->forceFill(['status' => ClaimStatus::VERIFYING])->save();

        /** @var VerificationRun $run */
        $run = VerificationRun::create([
            'organization_id' => $claim->organization_id,
            'claim_id' => $claim->id,
            'policy_id' => $policy?->id,
            'triggered_by' => $actor?->id,
            'status' => VerificationStatus::PLANNING,
            'assurance_level' => $policy?->assurance_level->value ?? 'STANDARD',
            'budget_max_tool_calls' => $policy?->max_tool_calls ?? 2,
            'budget_max_latency_ms' => $policy?->max_latency_ms ?? 12000,
            'started_at' => now(),
        ]);

        // One opening event, not two.
        //
        // The agent emits its own AGENT_STARTED and CONTEXT_LOADED as soon as
        // it receives the bundle, and both sets land on the same run — so the
        // tape used to open with "Claim received / Assurance policy loaded /
        // Claim received / Policy X loaded". The duplication made the trace
        // look unreliable, which is the one thing a trace cannot afford.
        //
        // What Laravel records here is the part the agent cannot know: that a
        // run was opened, by whom, against which claim. Everything about the
        // evidence gathering belongs to the agent and is left to it.
        $this->traceRecorder->append($run, TraceEventType::VERIFICATION_REQUESTED, 'Verification requested', [
            'claim' => $claim->reference,
            'policy' => $policy?->key,
            'evidence_budget' => $policy?->max_tool_calls,
        ]);

        return $run;
    }

    private function recordDecision(VerificationRun $run, Claim $claim, GuardedDecision $guarded, AgentResult $result): Decision
    {
        // Never overwrite: the previous decision stays readable in history.
        $previous = Decision::where('claim_id', $claim->id)->where('is_current', true)->get();

        $decision = Decision::create([
            'organization_id' => $run->organization_id,
            'verification_run_id' => $run->id,
            'claim_id' => $claim->id,
            'state' => $guarded->state->value,
            'recommended_action' => $guarded->recommendedAction()->value,
            'assurance_score' => $guarded->assuranceScore,
            'assurance_breakdown' => $guarded->breakdown,
            'rationale' => $guarded->rationale,
            'origin' => $guarded->guardApplied ? 'POLICY_GUARD' : 'AGENT',
            'policy_satisfied' => $guarded->policySatisfied,
            'guard_applied' => $guarded->guardApplied,
            'guard_reason' => $guarded->guardReason,
            'simulated' => $guarded->simulated || $result->usedDemoFallback,
            'is_current' => true,
        ]);

        foreach ($previous as $old) {
            $old->forceFill(['is_current' => false, 'superseded_by' => $decision->id])->save();
        }

        $this->audit->logAgent(AuditEventType::DECISION_CREATED, $decision, [
            'claim_reference' => $claim->reference,
            'work_order_reference' => $claim->workOrder?->reference,
            'state' => $decision->state->value,
            'assurance' => $decision->assurance_score,
            'simulated' => $decision->simulated,
        ], $run->organization_id);

        return $decision;
    }

    private function applyOutcome(Claim $claim, Decision $decision, ?VerificationPolicy $policy, GuardedDecision $guarded): void
    {
        // The claim is resolved either way: what changes is the decision
        // attached to it and whether a human still has to look.
        $claim->forceFill(['status' => ClaimStatus::RESOLVED])->save();

        $workOrder = $claim->workOrder;

        if ($workOrder) {
            $status = $decision->state->workOrderStatus();

            if ($decision->state === DecisionState::VERIFIED && $policy?->auto_close_on_verified) {
                $status = WorkOrderStatus::CLOSED;
            }

            $workOrder->forceFill(['status' => $status])->save();
        }

        if ($guarded->requiresReview()) {
            $this->openReview($claim, $decision, $guarded);
        }
    }

    private function openReview(Claim $claim, Decision $decision, GuardedDecision $guarded): Review
    {
        $existing = Review::where('claim_id', $claim->id)->pending()->first();

        if ($existing) {
            $existing->forceFill(['decision_id' => $decision->id])->save();

            return $existing;
        }

        $review = Review::create([
            'organization_id' => $claim->organization_id,
            'claim_id' => $claim->id,
            'decision_id' => $decision->id,
            'status' => ReviewStatus::OPEN->value,
            'reason' => match ($decision->state) {
                DecisionState::DISPUTED => 'DISPUTED_EVIDENCE',
                DecisionState::UNVERIFIED => 'UNVERIFIED',
                default => 'PARTIAL_ASSURANCE',
            },
        ]);

        $this->audit->logSystem(AuditEventType::REVIEW_CREATED, $review, [
            'claim_reference' => $claim->reference,
            'work_order_reference' => $claim->workOrder?->reference,
            'decision_state' => $decision->state->value,
            'missing_required' => $guarded->missingRequired,
        ], $claim->organization_id);

        return $review;
    }

    private function resolvePolicy(Claim $claim): ?VerificationPolicy
    {
        $workOrder = $claim->workOrder;

        if ($workOrder?->policy_id && $workOrder->policy) {
            return $workOrder->policy;
        }

        $key = $claim->organization?->default_policy_key ?? config('serviceproof.default_policy');

        return VerificationPolicy::where('organization_id', $claim->organization_id)
            ->where('key', $key)
            ->where('is_active', true)
            ->first()
            ?? VerificationPolicy::where('organization_id', $claim->organization_id)
                ->where('is_active', true)
                ->first();
    }
}
