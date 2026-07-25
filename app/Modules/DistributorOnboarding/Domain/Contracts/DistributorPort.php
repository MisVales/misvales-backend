<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Puerto de M05 para crear exclusivamente el perfil base autorizado. */
interface DistributorPort
{
    public function provision(
        string $applicationPublicId,
        string $name,
        int $branchId,
        string $operationKey,
    ): DistributorProvisionResult;
}
