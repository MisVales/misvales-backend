<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use App\Modules\Payment\Application\DTOs\VersionedMoney;

/** Resuelve configuración global versionada por fecha efectiva. */
interface PaymentConfigurationPort
{
    public function lateFeeFor(string $effectiveDate): VersionedMoney;
}
