<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;

final class FakeCashierVoucherAccess implements CashierVoucherAccessPort
{
    public int $assertions = 0;

    public function assertAttendable(string $voucherId, string $clientId, int $cashierUserId, int $branchId): void
    {
        $this->assertions++;
    }
}
