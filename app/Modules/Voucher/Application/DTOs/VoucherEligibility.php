<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

/** Resultado de las validaciones propietarias requeridas para liberar o feriar. */
final readonly class VoucherEligibility
{
    public function __construct(
        public string $clientBankAccountId,
        public int $creditDistributorId,
    ) {}
}
