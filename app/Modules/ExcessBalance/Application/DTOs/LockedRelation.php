<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\DTOs;

use Carbon\CarbonImmutable;

/** Snapshot autoritativo de M10 obtenido con relación y línea ya bloqueadas. */
final readonly class LockedRelation
{
    public function __construct(
        public string $relationId,
        public int $distributorId,
        public int $branchId,
        public string $pendingAmount,
        public bool $acceptsPayments,
        public bool $isSubsequent,
        public CarbonImmutable $availableAt,
    ) {}
}
