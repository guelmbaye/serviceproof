<?php

namespace App\Domain\Shared\Enums;

enum RecommendedAction: string
{
    case CLOSE = 'CLOSE';
    case REVIEW = 'REVIEW';
    case ESCALATE = 'ESCALATE';
    case MANUAL_VERIFICATION = 'MANUAL_VERIFICATION';

    /**
     * The consequence, not the recommendation.
     *
     * "Escalate to reviewer" describes what the system does next. What the
     * business needs to read is what happens to the money and the work order:
     * automatic closure is blocked and a person now has to look. Naming the
     * operational consequence is what makes an assurance decision worth
     * paying for rather than a status label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CLOSE => 'Close and proceed',
            self::REVIEW => 'Hold · additional review',
            self::ESCALATE => 'Hold · human review opened',
            self::MANUAL_VERIFICATION => 'Hold · manual verification',
        };
    }
}
