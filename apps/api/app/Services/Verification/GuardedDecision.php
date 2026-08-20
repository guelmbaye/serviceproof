<?php

namespace App\Services\Verification;

use App\Domain\Shared\Enums\DecisionState;
use App\Domain\Shared\Enums\RecommendedAction;

class GuardedDecision
{
    public function __construct(
        public readonly DecisionState $state,
        public readonly DecisionState $proposedState,
        public readonly bool $guardApplied,
        public readonly ?string $guardReason,
        public readonly string $rationale,
        public readonly bool $policySatisfied,
        public readonly int $assuranceScore,
        public readonly array $breakdown,
        public readonly bool $simulated,
        public readonly array $missingRequired = [],
    ) {}

    public function recommendedAction(): RecommendedAction
    {
        return $this->state->recommendedAction();
    }

    public function requiresReview(): bool
    {
        return $this->state->requiresHumanReview();
    }
}
