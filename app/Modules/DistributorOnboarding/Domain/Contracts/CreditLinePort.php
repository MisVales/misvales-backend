<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Puerto de M07 para línea, movimiento y restricción inicial. */
interface CreditLinePort
{
    public function openInitialLine(
        string $distributorId,
        string $amount,
        string $tolerance,
        string $operationKey,
    ): CreditLineProvisionResult;
}
