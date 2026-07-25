<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\DTOs;

use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class VoucherCapitalUsage
{
    public function __construct(
        public int $distributorId,
        public string $voucherId,
        public Money $capital,
        public ?int $actorUserId,
        public int $branchId,
        public string $reason,
        public string $idempotencyKey,
    ) {}
}
