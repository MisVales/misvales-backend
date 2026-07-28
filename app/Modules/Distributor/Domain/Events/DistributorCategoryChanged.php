<?php

namespace App\Modules\Distributor\Domain\Events;

class DistributorCategoryChanged
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $distributorId,
        public readonly string $distributorNumber,
        public readonly string $branchId,
        public readonly string $oldCategoryId,
        public readonly string $oldCategoryVersionId,
        public readonly string $newCategoryId,
        public readonly string $newCategoryVersionId,
        public readonly string $newProfitRateSnapshot,
        public readonly string $assignedBy,
        public readonly string $assignedRole,
        public readonly string $reason,
        public readonly \DateTimeImmutable $effectiveAt,
        public readonly string $idempotencyKey
    ) {}
}
