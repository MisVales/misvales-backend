<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Modules\Voucher\Application\DTOs\CreditBalanceSnapshot;

interface CreditBalanceSnapshotPort
{
    public function forDistributor(int $distributorId): CreditBalanceSnapshot;
}
