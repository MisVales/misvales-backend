<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Voucher\Application\Contracts\CreditBalanceSnapshotPort;
use App\Modules\Voucher\Application\DTOs\CreditBalanceSnapshot;

final class FakeCreditBalanceSnapshot implements CreditBalanceSnapshotPort
{
    public function forDistributor(int $distributorId): CreditBalanceSnapshot
    {
        return new CreditBalanceSnapshot('30000.00', '15000.00', '15000.00');
    }
}
