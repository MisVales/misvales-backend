<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTOs;

use App\Modules\Payment\Domain\DTOs\PendingComponents;
use Carbon\CarbonImmutable;

/** Snapshot bloqueado y autoritativo publicado por M10 para una aplicación. */
final readonly class RelationPaymentSnapshot
{
    public function __construct(
        public string $relationId,
        public int $distributorId,
        public int $branchId,
        public PendingComponents $pending,
        public CarbonImmutable $earlyPeriodStartsAt,
        public CarbonImmutable $dueDate,
        public int $lockVersion,
    ) {}
}
