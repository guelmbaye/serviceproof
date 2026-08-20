<?php

namespace App\Domain\Shared\Enums;

enum RecommendedAction: string
{
    case CLOSE = 'CLOSE';
    case REVIEW = 'REVIEW';
    case ESCALATE = 'ESCALATE';
    case MANUAL_VERIFICATION = 'MANUAL_VERIFICATION';

    public function label(): string
    {
        return match ($this) {
            self::CLOSE => 'Recommend closure',
            self::REVIEW => 'Request additional review',
            self::ESCALATE => 'Escalate to reviewer',
            self::MANUAL_VERIFICATION => 'Manual verification required',
        };
    }
}
