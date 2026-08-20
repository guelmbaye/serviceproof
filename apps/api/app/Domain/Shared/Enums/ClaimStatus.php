<?php

namespace App\Domain\Shared\Enums;

enum ClaimStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case VERIFYING = 'VERIFYING';
    case RESOLVED = 'RESOLVED';
    case FAILED = 'FAILED';

    public function canStartVerification(): bool
    {
        // RESOLVED claims can be re-verified (a new run, never an overwrite).
        return in_array($this, [self::SUBMITTED, self::FAILED, self::RESOLVED], true);
    }
}
