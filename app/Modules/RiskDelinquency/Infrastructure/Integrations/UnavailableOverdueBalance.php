<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Integrations;

use App\Modules\RiskDelinquency\Application\Contracts\OverdueBalancePort;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;

/** Impide decidir con un saldo no confirmado por M11. */
final class UnavailableOverdueBalance implements OverdueBalancePort
{
    public function totalForDistributor(int $distributorId): string
    {
        throw RiskDelinquencyException::sourceUnavailable();
    }
}
