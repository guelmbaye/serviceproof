<?php

namespace App\Domain\Shared\Enums;

enum ReviewStatus: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case RESOLVED = 'RESOLVED';
}
