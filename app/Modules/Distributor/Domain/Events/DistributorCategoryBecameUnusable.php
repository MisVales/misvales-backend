<?php

namespace App\Modules\Distributor\Domain\Events;

class DistributorCategoryBecameUnusable
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $distributorId,
        public readonly string $distributorNumber,
        public readonly string $categoryId,
        public readonly string $categoryVersionId,
        public readonly \DateTimeImmutable $effectiveAt
    ) {}
}
