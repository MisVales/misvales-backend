<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\DTOs;

use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class InitialCreditAuthorization
{
    public function __construct(
        public int $distributorId,
        public Money $authorizedAmount,
        public int $authorizedByUserId,
        public int $branchId,
        public string $reason,
        public string $authorizationId,
        public string $idempotencyKey,
        public bool $isFinal = true,
    ) {}
}
