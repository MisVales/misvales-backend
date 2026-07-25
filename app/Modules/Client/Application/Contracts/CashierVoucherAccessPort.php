<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Confirma en M08 que la cajera puede atender ese vale en esa sucursal. */
interface CashierVoucherAccessPort
{
    public function assertAttendable(
        string $voucherId,
        string $clientId,
        int $cashierUserId,
        int $branchId,
    ): void;
}
