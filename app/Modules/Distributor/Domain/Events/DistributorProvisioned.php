<?php

namespace App\Modules\Distributor\Domain\Events;

class DistributorProvisioned
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $distributorId,
        public readonly string $distributorNumber,
        public readonly string $branchId,
        public readonly ?string $activatedBy,
        public readonly \DateTimeImmutable $effectiveAt,
        public readonly string $operationId,
        public readonly string $idempotencyKey
    ) {
    }
}
