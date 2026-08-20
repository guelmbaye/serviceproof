<?php

namespace App\Domain\Shared\Enums;

enum AssuranceLevel: string
{
    case LOW = 'LOW';
    case STANDARD = 'STANDARD';
    case HIGH = 'HIGH';

    public function defaultBudget(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::STANDARD => 2,
            self::HIGH => 3,
        };
    }
}
