<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

/** Fuente autoritativa M11 del saldo vencido agregado. */
interface OverdueBalancePort
{
    public function totalForDistributor(int $distributorId): string;
}
