<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Entities;

use App\Modules\Credit\Domain\Enums\CreditMovementType;
use App\Modules\Credit\Domain\ValueObjects\Money;
use Carbon\CarbonImmutable;

final readonly class CreditMovement
{
    public function __construct(
        public string $id,
        public CreditMovementType $type,
        public Money $totalBefore,
        public Money $totalAfter,
        public Money $usedBefore,
        public Money $usedAfter,
        public string $sourceType,
        public string $sourceId,
        public CarbonImmutable $occurredAt,
    ) {}
}
