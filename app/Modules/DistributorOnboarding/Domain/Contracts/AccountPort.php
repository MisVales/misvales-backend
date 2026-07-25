<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Puerto de M01 para disponibilidad de correo y aprovisionamiento idempotente. */
interface AccountPort
{
    public function assertEmailAvailable(string $normalizedEmail): void;

    public function provisionDistributor(
        string $normalizedEmail,
        string $name,
        int $branchId,
        string $operationKey,
    ): AccountProvisionResult;
}
