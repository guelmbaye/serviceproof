<?php

namespace App\Domain\Shared\Enums;

enum EvidenceStatus: string
{
    case SUPPORTED = 'SUPPORTED';
    case CONFLICTING = 'CONFLICTING';
    case UNAVAILABLE = 'UNAVAILABLE';
    case STALE = 'STALE';
    case INVALID = 'INVALID';

    /** Does this item actually carry decision weight? */
    public function isUsable(): bool
    {
        return in_array($this, [self::SUPPORTED, self::CONFLICTING], true);
    }

    /**
     * UNAVAILABLE is never negative evidence. An API failure says nothing
     * about whether the service happened.
     */
    public function isNegative(): bool
    {
        return $this === self::CONFLICTING;
    }
}
