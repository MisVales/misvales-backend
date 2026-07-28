<?php

namespace App\Modules\Distributor\Domain\Events;

class DistributorCategoryAssigned
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $distributorId,
        public readonly string $distributorNumber,
        public readonly string $branchId,
        public readonly string $categoryId,
        public readonly string $categoryVersionId,
        public readonly string $profitRateSnapshot,
        public readonly string $assignedBy,
        public readonly string $assignedRole,
        public readonly string $reason,
        public readonly \DateTimeImmutable $effectiveAt,
        public readonly string $idempotencyKey
    ) {}
}
