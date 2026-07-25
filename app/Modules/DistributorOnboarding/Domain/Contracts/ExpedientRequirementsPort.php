<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Puerto hacia la matriz aprobada de campos y documentos obligatorios. */
interface ExpedientRequirementsPort
{
    public function assertComplete(DistributorApplication $application): void;
}
