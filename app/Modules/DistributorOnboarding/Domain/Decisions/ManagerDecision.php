<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Decisions;

/** Decisiones cerradas de la autorización gerencial. */
enum ManagerDecision: string
{
    case APPROVE = 'APPROVE';
    case REJECT = 'REJECT';
}
