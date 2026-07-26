<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

/** Valida referencias de cartera únicamente contra un feriado confirmado. */
final class VoucherConfirmedPort implements ConfirmedVoucherPort
{
    public function assertConfirmedForClient(
        string $voucherId,
        string $clientId,
        string $distributorId,
        string $amount,
    ): void {
        $confirmed = VoucherModel::query()
            ->whereKey($voucherId)
            ->where('client_id', $clientId)
            ->where('distributor_id', $distributorId)
            ->where('capital_amount', $amount)
            ->where('status', VoucherStatus::FULFILLED->value)
            ->exists();
        if (! $confirmed) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }
    }
}
