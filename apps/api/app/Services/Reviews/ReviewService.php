<?php

namespace App\Services\Reviews;

use App\Domain\Decisions\Models\Decision;
use App\Domain\Identity\Models\User;
use App\Domain\Reviews\Models\Review;
use App\Domain\Shared\Enums\AuditEventType;
use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Shared\Enums\ReviewStatus;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function claimForReview(Review $review, User $reviewer): Review
    {
        $review->forceFill([
            'assigned_to' => $reviewer->id,
            'status' => ReviewStatus::IN_PROGRESS->value,
        ])->save();

        return $review;
    }

    /**
     * A human reviewer resolves an exception.
     *
     * An override never rewrites the automated decision: it creates a new
     * HUMAN-origin decision, marks the previous one superseded and leaves
     * the full history readable.
     */
    public function resolve(Review $review, User $reviewer, string $outcome, ?string $overrideState, ?string $notes): Review
    {
        return DB::transaction(function () use ($review, $reviewer, $outcome, $overrideState, $notes) {
            $review->loadMissing(['decision', 'claim.workOrder']);
            $original = $review->decision;

            if ($outcome === 'OVERRIDDEN' && $overrideState) {
                $state = DecisionState::from($overrideState);

                $newDecision = Decision::create([
                    'organization_id' => $review->organization_id,
                    'verification_run_id' => $original?->verification_run_id,
                    'claim_id' => $review->claim_id,
                    'state' => $state->value,
                    'recommended_action' => $state->recommendedAction()->value,
                    'assurance_score' => $original?->assurance_score,
                    'assurance_breakdown' => $original?->assurance_breakdown,
                    'rationale' => $notes ?: 'Resolved by human reviewer.',
                    'origin' => 'HUMAN',
                    'policy_satisfied' => $original?->policy_satisfied ?? false,
                    'guard_applied' => false,
                    'simulated' => $original?->simulated ?? false,
                    'is_current' => true,
                ]);

                Decision::where('claim_id', $review->claim_id)
                    ->where('is_current', true)
                    ->whereKeyNot($newDecision->id)
                    ->get()
                    ->each(fn (Decision $d) => $d->forceFill([
                        'is_current' => false,
                        'superseded_by' => $newDecision->id,
                    ])->save());

                $review->forceFill(['decision_id' => $newDecision->id])->save();

                $this->audit->log(AuditEventType::DECISION_OVERRIDDEN, $newDecision, [
                    'claim_reference' => $review->claim?->reference,
                    'work_order_reference' => $review->claim?->workOrder?->reference,
                    'original_state' => $original?->state->value,
                    'new_state' => $state->value,
                    'reason' => $notes,
                ], $reviewer);

                if ($claim = $review->claim) {
                    $claim->workOrder?->forceFill(['status' => $state->workOrderStatus()->value])->save();
                }
            }

            $review->forceFill([
                'status' => ReviewStatus::RESOLVED->value,
                'outcome' => $outcome,
                'override_state' => $overrideState,
                'resolution_notes' => $notes,
                'resolved_by' => $reviewer->id,
                'resolved_at' => now(),
            ])->save();

            $this->audit->log(AuditEventType::REVIEW_RESOLVED, $review, [
                'claim_reference' => $review->claim?->reference,
                'work_order_reference' => $review->claim?->workOrder?->reference,
                'outcome' => $outcome,
                'override_state' => $overrideState,
            ], $reviewer);

            return $review->fresh(['decision', 'claim']);
        });
    }
}
