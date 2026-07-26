<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

final readonly class CreditBalanceSnapshot
{
    public function __construct(
        public string $total,
        public string $used,
        public string $available,
    ) {}
}
