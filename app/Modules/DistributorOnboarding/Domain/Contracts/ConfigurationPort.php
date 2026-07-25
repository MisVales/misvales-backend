<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Puerto de M03 para validar importes y obtener configuración vigente sin copiar su lógica. */
interface ConfigurationPort
{
    public function assertInitialCreditLineAllowed(string $amount): void;

    public function firstVoucherTolerance(): string;
}
