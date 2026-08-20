<?php

namespace App\Domain\Shared\Enums;

enum WorkOrderStatus: string
{
    case SCHEDULED = 'SCHEDULED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case AWAITING_VERIFICATION = 'AWAITING_VERIFICATION';
    case VERIFIED = 'VERIFIED';
    case DISPUTED = 'DISPUTED';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::CLOSED, self::CANCELLED], true);
    }

    public function acceptsClaim(): bool
    {
        return in_array($this, [self::SCHEDULED, self::IN_PROGRESS, self::AWAITING_VERIFICATION, self::NEEDS_REVIEW], true);
    }
}
