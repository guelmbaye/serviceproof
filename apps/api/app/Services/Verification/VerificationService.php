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
use Throwable;

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
        // A claim stuck mid-verification is recovered rather than refused.
        //
        // openRun() moves the claim to VERIFYING before the agent is called.
        // If the process dies in between — a container restart, an OOM kill —
        // nothing ever moves it back, and the record is locked out of the
        // system permanently. Anything older than the agent's own timeout is
        // not in progress; it is abandoned.
        if ($claim->status->isVerifying()) {
            $this->reclaimAbandonedRun($claim);
            $claim->refresh();
        }

        if (! $claim->status->canStartVerification()) {
            throw new RuntimeException("Claim {$claim->reference} cannot be verified in state {$claim->status->value}.");
        }

        // Load everything the loop touches up front: the agent bundle, the
        // guard and the outcome propagation all read these relations.
        $claim->loadMissing(['workOrder.policy', 'workOrder.device', 'user', 'device', 'organization']);

        $policy = $this->resolvePolicy($claim);
        $run = $this->openRun($claim, $policy, $actor);

        $this->audit->log(AuditEventType::VERIFICATION_STARTED, $run, [
            'claim_reference' => $claim->reference,
            'policy' => $policy?->key,
            'budget' => $run->budget_max_tool_calls,
        ], $actor);

        // ── Call the agent runtime (single bounded round trip) ────────────
        //
        // Everything from here on is wrapped: openRun() has already moved the
        // claim to VERIFYING, and any throw between that point and the end of
        // the transaction would leave it there permanently. canStartVerification()
        // refuses VERIFYING, so the claim became unverifiable through the API —
        // a bug in one run bricked the record it was about.
        try {
            $bundle = $this->contextBuilder->build($run, $claim, $options);
            $startedAt = microtime(true);
            $result = $this->agent->verify($bundle);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->audit->logAgent(AuditEventType::AGENT_INVOKED, $run, $this->auditTrail($claim) + [
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
                    $this->audit->logAgent(AuditEventType::EVIDENCE_RECEIVED, $item, $this->auditTrail($claim) + [
                        'type' => $item->type->value,
                        'status' => $item->status->value,
                        'source' => $item->source->value,
                        'request_id' => $item->request_id,
                    ], $run->organization_id);
                }

                if ($evidence->contains(fn ($e) => $e->status->isNegative())) {
                    $this->audit->logAgent(AuditEventType::EVIDENCE_CONFLICT, $run, $this->auditTrail($claim) + [
                        'conflicting' => $evidence->filter(fn ($e) => $e->status->isNegative())->pluck('type')->map->value->all(),
                    ], $run->organization_id);
                }

                if ($result->escalated) {
                    $this->audit->logAgent(AuditEventType::ESCALATION, $run, $this->auditTrail($claim) + [
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

                    $this->audit->logSystem(AuditEventType::AGENT_FAILURE, $run, $this->auditTrail($claim) + [
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

                    $this->audit->logSystem(AuditEventType::DECISION_GUARD_APPLIED, $run, $this->auditTrail($claim) + [
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
        } catch (Throwable $exception) {
            // Release the claim so it can be tried again, and leave a record
            // of why it could not be decided this time. The run is kept:
            // a failed verification is still part of the claim's history.
            $this->releaseFailedRun($run, $claim, $exception);

            throw $exception;
        }
    }

    /**
     * Release a claim whose verification never finished.
     *
     * The cut-off is the agent timeout plus a margin: nothing legitimate runs
     * longer, so a RUNNING record older than that has no process behind it.
     * Being conservative here matters — reclaiming a run that is genuinely in
     * flight would let two verifications write to the same claim.
     */
    private function reclaimAbandonedRun(Claim $claim): void
    {
        $cutoff = now()->subSeconds((int) config('services.agent.timeout', 45) + 60);

        // Any non-terminal status: a run opens as PLANNING and moves through
        // COLLECTING_EVIDENCE and the rest. There is no single RUNNING value,
        // so the query asks for "not finished" rather than naming a state.
        $openStatuses = array_values(array_filter(
            VerificationStatus::cases(),
            fn (VerificationStatus $status) => ! $status->isTerminal(),
        ));

        $stranded = VerificationRun::where('claim_id', $claim->id)
            ->whereIn('status', array_map(fn ($status) => $status->value, $openStatuses))
            ->where('started_at', '<', $cutoff)
            ->get();

        if ($stranded->isEmpty()) {
            // Still within the window: a run may genuinely be in flight, and
            // refusing is the safe answer.
            return;
        }

        foreach ($stranded as $run) {
            $this->releaseFailedRun($run, $claim, new RuntimeException(
                'Verification was abandoned: no result was recorded before the timeout elapsed.'
            ));
        }
    }

    /**
     * Hand the claim back after a run that could not finish.
     *
     * openRun() moves the claim to VERIFYING before the agent is called. If
     * anything throws after that — a bug here, an agent timeout, a container
     * killed mid-request — the claim would otherwise stay VERIFYING forever,
     * and canStartVerification() refuses that state. The record becomes
     * unverifiable through the API, permanently, because of a transient
     * failure.
     *
     * The run is marked FAILED and kept. A verification that did not complete
     * is still part of the claim's history, and deleting it would be the kind
     * of tidying this system exists to prevent.
     */
    private function releaseFailedRun(VerificationRun $run, Claim $claim, Throwable $exception): void
    {
        try {
            $run->forceFill([
                'status' => VerificationStatus::FAILED,
                'failure_reason' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            // Back to SUBMITTED, which canStartVerification() allows, so the
            // technician's claim can be tried again rather than stranded.
            $claim->forceFill(['status' => ClaimStatus::SUBMITTED])->save();

            $this->audit->logSystem(AuditEventType::AGENT_FAILURE, $run, $this->auditTrail($claim) + [
                'reason' => $exception->getMessage(),
                'exception' => $exception::class,
            ], $run->organization_id);
        } catch (Throwable $ignored) {
            // The original exception is the one worth reporting. Failing to
            // record the failure must not replace it with a less useful one.
        }
    }

    /**
     * The case an audit event belongs to.
     *
     * Every event in a run carries this. Without it the log is a wall of
     * UUIDs: one verification writes a dozen rows, and only the first said
     * which claim it was about.
     *
     * A method rather than a local variable, deliberately. The first version
     * was a `$trail` array built in verify(), and the closure passed to
     * DB::transaction did not `use ($trail)` — PHP closures capture nothing
     * implicitly, so every audit call inside the transaction threw
     * "Undefined variable $trail" at runtime. `php -l` cannot see that, and
     * neither can a reader skimming a long `use` list. Nothing to capture is
     * the only version that cannot go wrong.
     */
    private function auditTrail(Claim $claim): array
    {
        return [
            'claim_reference' => $claim->reference,
            'work_order_reference' => $claim->workOrder?->reference,
        ];
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
