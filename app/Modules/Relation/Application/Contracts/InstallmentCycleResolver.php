<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Contracts;

use Carbon\CarbonImmutable;

interface InstallmentCycleResolver
{
    /**
     * Resolves the eligible installments for a given distributor and cut date.
     * The business rules for this are pending definition.
     *
     * @param string $distributorId
     * @param CarbonImmutable $cutDate
     * @return array<string> Array of voucher_installment_id
     */
    public function resolveEligibleInstallments(string $distributorId, CarbonImmutable $cutDate): array;
}
