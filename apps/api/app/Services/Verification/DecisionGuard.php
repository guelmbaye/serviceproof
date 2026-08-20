<?php

namespace App\Services\Verification;

use App\Domain\Evidence\Models\Evidence;
use App\Domain\Policies\Models\VerificationPolicy;
use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Shared\Enums\EvidenceStatus;
use Illuminate\Support\Collection;

/**
 * The deterministic authority.
 *
 * The agent proposes a decision. This class re-derives the outcome from the
 * persisted evidence and the organisation's policy, and the derived outcome
 * wins. An LLM can never talk the system into VERIFIED.
 */
class DecisionGuard
{
    /**
     * @param  Collection<int, Evidence>  $evidence
     */
    public function apply(Collection $evidence, ?VerificationPolicy $policy, ?array $proposed): GuardedDecision
    {
        $usable = $evidence->filter(fn (Evidence $e) => $e->isUsable());
        $supported = $usable->filter(fn (Evidence $e) => $e->status === EvidenceStatus::SUPPORTED);
        $conflicting = $usable->filter(fn (Evidence $e) => $e->status === EvidenceStatus::CONFLICTING);
        $stale = $evidence->filter(fn (Evidence $e) => $e->status === EvidenceStatus::STALE);
        $unavailable = $evidence->filter(fn (Evidence $e) => $e->status === EvidenceStatus::UNAVAILABLE);
        $simulated = $evidence->contains(fn (Evidence $e) => $e->isSimulated());

        $requiredTypes = $policy?->requiredEvidenceTypes() ?? [];
        $satisfiedTypes = $supported->pluck('type')->map(fn ($t) => $t->value)->unique();

        $missingRequired = collect($requiredTypes)
            ->map(fn ($type) => $type->value)
            ->reject(fn (string $type) => $satisfiedTypes->contains($type))
            ->values();

        $proposedState = DecisionState::tryFrom($proposed['state'] ?? '') ?? DecisionState::UNVERIFIED;

        // ── Deterministic derivation ──────────────────────────────────────
        if ($usable->isEmpty()) {
            // An unavailable API is not negative evidence. It is simply an
            // absence of evidence.
            $derived = DecisionState::UNVERIFIED;
            $reason = $unavailable->isNotEmpty()
                ? 'No usable network evidence was returned (API unavailable).'
                : 'No usable network evidence was collected.';
        } elseif ($conflicting->isNotEmpty()) {
            $derived = DecisionState::DISPUTED;
            $reason = sprintf(
                '%d evidence item(s) materially conflict with the claim; automatic verification is not permitted.',
                $conflicting->count()
            );
        } elseif ($missingRequired->isEmpty()) {
            $derived = DecisionState::VERIFIED;
            $reason = 'All evidence required by policy '.($policy?->key ?? 'default').' is supported.';
        } elseif ($policy?->allow_partial && $supported->isNotEmpty()) {
            $derived = DecisionState::PARTIAL;
            $reason = 'Supporting evidence exists but the policy requirements are incomplete: '
                .$missingRequired->implode(', ').'.';
        } else {
            $derived = DecisionState::UNVERIFIED;
            $reason = 'Required evidence is missing and this policy does not allow partial assurance: '
                .$missingRequired->implode(', ').'.';
        }

        $guardApplied = $derived !== $proposedState;

        $breakdown = $this->breakdown(
            evidenceTotal: $evidence->count(),
            usable: $usable->count(),
            supported: $supported->count(),
            conflicting: $conflicting->count(),
            unavailable: $unavailable->count(),
            stale: $stale->count(),
            requiredTotal: count($requiredTypes),
            requiredSatisfied: count($requiredTypes) - $missingRequired->count(),
            simulated: $simulated,
            derived: $derived,
        );

        return new GuardedDecision(
            state: $derived,
            proposedState: $proposedState,
            guardApplied: $guardApplied,
            guardReason: $guardApplied
                ? sprintf('Agent proposed %s; deterministic policy layer resolved %s. %s', $proposedState->value, $derived->value, $reason)
                : null,
            rationale: $reason,
            policySatisfied: $derived === DecisionState::VERIFIED,
            assuranceScore: $breakdown['score'],
            breakdown: $breakdown,
            simulated: $simulated,
            missingRequired: $missingRequired->all(),
        );
    }

    /**
     * Explainable assurance. Deliberately not an opaque model confidence:
     * every component can be shown in the UI and defended to a reviewer.
     */
    private function breakdown(
        int $evidenceTotal,
        int $usable,
        int $supported,
        int $conflicting,
        int $unavailable,
        int $stale,
        int $requiredTotal,
        int $requiredSatisfied,
        bool $simulated,
        DecisionState $derived,
    ): array {
        $completeness = $requiredTotal > 0 ? $requiredSatisfied / $requiredTotal : ($supported > 0 ? 1.0 : 0.0);
        $consistency = $usable > 0 ? ($usable - $conflicting) / $usable : 0.0;
        $freshness = $evidenceTotal > 0 ? ($evidenceTotal - $stale) / $evidenceTotal : 0.0;
        $availability = $evidenceTotal > 0 ? ($evidenceTotal - $unavailable) / $evidenceTotal : 0.0;

        $score = (int) round(100 * (
            0.40 * $completeness +
            0.35 * $consistency +
            0.15 * $freshness +
            0.10 * $availability
        ));

        if ($derived === DecisionState::UNVERIFIED) {
            $score = min($score, 25);
        }

        return [
            'score' => max(0, min(100, $score)),
            'components' => [
                'completeness' => round($completeness, 3),
                'consistency' => round($consistency, 3),
                'freshness' => round($freshness, 3),
                'availability' => round($availability, 3),
            ],
            'weights' => ['completeness' => 0.40, 'consistency' => 0.35, 'freshness' => 0.15, 'availability' => 0.10],
            'counts' => [
                'total' => $evidenceTotal,
                'usable' => $usable,
                'supported' => $supported,
                'conflicting' => $conflicting,
                'unavailable' => $unavailable,
                'stale' => $stale,
                'required_total' => $requiredTotal,
                'required_satisfied' => $requiredSatisfied,
            ],
            'provenance' => $simulated ? 'INCLUDES_SIMULATED_EVIDENCE' : 'LIVE_NETWORK_EVIDENCE',
        ];
    }
}
