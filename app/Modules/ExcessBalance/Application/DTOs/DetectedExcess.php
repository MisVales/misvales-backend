<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\DTOs;

use Carbon\CarbonImmutable;

/** Entrada pública de M11 después de confirmar el cálculo del pago mayor. */
final readonly class DetectedExcess
{
    public function __construct(
        public string $bankMovementId,
        public string $paymentAllocationId,
        public string $relationId,
        public int $distributorId,
        public int $branchId,
        public string $paidAmount,
        public string $previousBalance,
        public string $appliedAmount,
        public string $excessAmount,
        public CarbonImmutable $effectivePaidAt,
        public string $idempotencyKey,
        public string $correlationId,
    ) {}
}
