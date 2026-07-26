<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\BankCoveragePort;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Deniega evaluaciones posteriores hasta definir la cobertura bancaria completa. */
final class UnavailableBankCoveragePort implements BankCoveragePort
{
    public function processedImportIdFor(int $branchId, string $businessDate): ?string
    {
        throw PaymentDomainException::bankCoverageContractUnavailable();
    }
}
