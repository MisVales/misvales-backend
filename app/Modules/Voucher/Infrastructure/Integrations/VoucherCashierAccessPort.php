<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

/** Confirma a M06 que el vale puede exponer datos de verificación a esa cajera. */
final class VoucherCashierAccessPort implements CashierVoucherAccessPort
{
    public function assertAttendable(
        string $voucherId,
        string $clientId,
        int $cashierUserId,
        int $branchId,
    ): void {
        $allowed = VoucherModel::query()
            ->whereKey($voucherId)
            ->where('client_id', $clientId)
            ->where('branch_id', $branchId)
            ->whereIn('status', [
                VoucherStatus::COUNTER_VALIDATION->value,
                VoucherStatus::CORRECTION_PENDING->value,
                VoucherStatus::RELEASED->value,
            ])
            ->exists();
        if (! $allowed) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }
    }
}
