<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Services;

use App\Modules\Voucher\Application\Contracts\VoucherGenerationRepository;
use App\Modules\Voucher\Domain\Enums\VoucherType;

/** El historial global y una transferencia previa determinan el tipo; nunca Angular. */
final readonly class VoucherTypeResolver
{
    public function __construct(private VoucherGenerationRepository $vouchers) {}

    public function resolve(string $clientId, bool $wasTransferred): VoucherType
    {
        return $wasTransferred || $this->vouchers->clientHasHistory($clientId)
            ? VoucherType::DIGITAL
            : VoucherType::PREVALE;
    }
}
