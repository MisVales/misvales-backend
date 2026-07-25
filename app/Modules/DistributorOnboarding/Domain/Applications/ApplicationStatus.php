<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Applications;

/** Estados cerrados del ciclo de vida de una solicitud de distribuidora. */
enum ApplicationStatus: string
{
    case CAPTURE = 'CAPTURE';
    case COORDINATOR_REVIEW = 'COORDINATOR_REVIEW';
    case VISIT_ASSIGNED = 'VISIT_ASSIGNED';
    case PHYSICAL_VERIFICATION = 'PHYSICAL_VERIFICATION';
    case COORDINATOR_CORRECTION = 'COORDINATOR_CORRECTION';
    case COORDINATOR_EVALUATION = 'COORDINATOR_EVALUATION';
    case TERMINATED_UNFAVORABLE = 'TERMINATED_UNFAVORABLE';
    case MANAGER_AUTHORIZATION = 'MANAGER_AUTHORIZATION';
    case REJECTED = 'REJECTED';
    case ACTIVE = 'ACTIVE';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::TERMINATED_UNFAVORABLE,
            self::REJECTED,
            self::ACTIVE,
        ], true);
    }
}
