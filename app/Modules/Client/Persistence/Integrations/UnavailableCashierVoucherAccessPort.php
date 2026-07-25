<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Integrations;

use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Deniega PII de caja mientras M08 no confirme un vale atendible. */
final class UnavailableCashierVoucherAccessPort implements CashierVoucherAccessPort
{
    public function assertAttendable(string $voucherId, string $clientId, int $cashierUserId, int $branchId): void
    {
        throw ClientDomainException::integrationUnavailable('M08_CASHIER_VOUCHER_ACCESS');
    }
}
