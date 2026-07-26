<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum RiskAlertStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RESOLVED_BY_DECISION = 'RESOLVED_BY_DECISION';
    case FINANCIALLY_REGULARIZED = 'FINANCIALLY_REGULARIZED';
    case SUPERSEDED = 'SUPERSEDED';
}
