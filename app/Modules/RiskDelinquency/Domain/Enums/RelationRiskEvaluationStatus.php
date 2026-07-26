<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

/** Estado inmutable de una versión de evaluación posterior al vencimiento. */
enum RelationRiskEvaluationStatus: string
{
    case PENDING_SOURCE = 'PENDING_SOURCE';
    case COMPLIANT = 'COMPLIANT';
    case BREACHED = 'BREACHED';
    case SUPERSEDED = 'SUPERSEDED';
}
