<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\PaymentConfigurationPort;
use App\Modules\Payment\Application\DTOs\VersionedMoney;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Deniega recargos mientras M03 no publique configuración monetaria versionada. */
final class UnavailablePaymentConfigurationPort implements PaymentConfigurationPort
{
    public function lateFeeFor(string $effectiveDate): VersionedMoney
    {
        throw PaymentDomainException::configurationContractUnavailable();
    }
}
