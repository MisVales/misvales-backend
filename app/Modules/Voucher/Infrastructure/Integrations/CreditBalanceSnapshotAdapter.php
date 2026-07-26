<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Voucher\Application\Contracts\CreditBalanceSnapshotPort;
use App\Modules\Voucher\Application\DTOs\CreditBalanceSnapshot;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/** Adaptador de lectura posterior al movimiento, sin exponer modelos a Presentation. */
final class CreditBalanceSnapshotAdapter implements CreditBalanceSnapshotPort
{
    public function forDistributor(int $distributorId): CreditBalanceSnapshot
    {
        $line = CreditLineModel::query()->where('distributor_id', $distributorId)->first();
        if ($line === null) {
            throw VoucherDomainException::dependencyUnavailable('M07_CREDIT_LINE');
        }

        return new CreditBalanceSnapshot(
            total: $line->total_authorized,
            used: $line->used_balance,
            available: $line->available_balance,
        );
    }
}
