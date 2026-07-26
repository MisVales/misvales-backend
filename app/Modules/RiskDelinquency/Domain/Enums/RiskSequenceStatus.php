<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum RiskSequenceStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RESET_BY_COMPLIANCE = 'RESET_BY_COMPLIANCE';
    case RESET_BY_REGULARIZATION = 'RESET_BY_REGULARIZATION';
    case SUPERSEDED = 'SUPERSEDED';
}
