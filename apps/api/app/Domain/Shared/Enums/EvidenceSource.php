<?php

namespace App\Domain\Shared\Enums;

enum EvidenceSource: string
{
    case CAMARA = 'CAMARA';
    case DEMO_FALLBACK = 'DEMO_FALLBACK';

    public function isLive(): bool
    {
        return $this === self::CAMARA;
    }

    public function label(): string
    {
        return match ($this) {
            self::CAMARA => 'Live network evidence',
            self::DEMO_FALLBACK => 'Simulated (demo fallback)',
        };
    }
}
