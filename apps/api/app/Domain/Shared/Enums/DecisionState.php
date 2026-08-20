<?php

namespace App\Domain\Shared\Enums;

enum DecisionState: string
{
    case VERIFIED = 'VERIFIED';
    case PARTIAL = 'PARTIAL';
    case DISPUTED = 'DISPUTED';
    case UNVERIFIED = 'UNVERIFIED';

    public function recommendedAction(): RecommendedAction
    {
        return match ($this) {
            self::VERIFIED => RecommendedAction::CLOSE,
            self::PARTIAL => RecommendedAction::REVIEW,
            self::DISPUTED => RecommendedAction::ESCALATE,
            self::UNVERIFIED => RecommendedAction::MANUAL_VERIFICATION,
        };
    }

    public function requiresHumanReview(): bool
    {
        return $this !== self::VERIFIED;
    }

    public function workOrderStatus(): WorkOrderStatus
    {
        return match ($this) {
            self::VERIFIED => WorkOrderStatus::VERIFIED,
            self::DISPUTED => WorkOrderStatus::DISPUTED,
            default => WorkOrderStatus::NEEDS_REVIEW,
        };
    }
}
