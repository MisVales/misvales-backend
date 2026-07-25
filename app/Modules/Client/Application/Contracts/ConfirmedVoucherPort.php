<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Valida que un cargo de cartera corresponde a un vale confirmado por M08. */
interface ConfirmedVoucherPort
{
    public function assertConfirmedForClient(
        string $voucherId,
        string $clientId,
        string $distributorId,
        string $amount,
    ): void;
}
