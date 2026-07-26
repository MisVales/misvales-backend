<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use App\Modules\Payment\Application\DTOs\RelationPaymentSnapshot;
use App\Modules\Payment\Domain\DTOs\PaymentAllocation;
use Carbon\CarbonImmutable;

/**
 * Frontera pública requerida de M10.
 *
 * M11 nunca consulta ni mantiene tablas internas de relaciones.
 */
interface RelationPaymentPort
{
    public function normalizeReference(string $rawReference): string;

    public function lockByExactReference(string $normalizedReference, int $branchId): ?RelationPaymentSnapshot;

    public function lockById(string $relationId, int $branchId, int $distributorId): RelationPaymentSnapshot;

    public function apply(
        RelationPaymentSnapshot $relation,
        PaymentAllocation $allocation,
        string $paymentAllocationId,
        CarbonImmutable $effectiveAt,
    ): void;
}
