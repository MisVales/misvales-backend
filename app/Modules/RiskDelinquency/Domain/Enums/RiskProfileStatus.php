<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum RiskProfileStatus: string
{
    case CURRENT = 'CURRENT';
    case REBUILD_REQUIRED = 'REBUILD_REQUIRED';
    case REBUILDING = 'REBUILDING';
    case INCONSISTENT = 'INCONSISTENT';
}
