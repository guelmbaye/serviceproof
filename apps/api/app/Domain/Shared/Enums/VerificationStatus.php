<?php

namespace App\Domain\Shared\Enums;

enum VerificationStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PLANNING = 'PLANNING';
    case COLLECTING_EVIDENCE = 'COLLECTING_EVIDENCE';
    case EVALUATING = 'EVALUATING';
    case ESCALATING = 'ESCALATING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED], true);
    }
}
