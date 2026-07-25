<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Impide fabricar cargos de vale cuando M08 aún no está conectado. */
final class UnavailableConfirmedVoucherPort implements ConfirmedVoucherPort
{
    public function assertConfirmedForClient(string $voucherId, string $clientId, string $distributorId, string $amount): void
    {
        throw ClientDomainException::integrationUnavailable('M08_CONFIRMED_VOUCHER');
    }
}
