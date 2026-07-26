<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Contracts;

use App\Modules\ExcessBalance\Application\DTOs\CreditBalancePaymentResult;
use App\Modules\ExcessBalance\Application\DTOs\LockedRelation;

/**
 * Frontera conjunta de M10/M11.
 *
 * El primer método conserva el orden global relación -> línea; el segundo usa
 * esos locks para aplicar con source_type=CREDIT_BALANCE.
 */
interface CreditBalanceApplicationPort
{
    public function lockRelationAndCredit(
        string $relationId,
        int $distributorId,
        int $branchId,
    ): LockedRelation;

    public function apply(
        LockedRelation $relation,
        string $excessApplicationId,
        string $paymentAllocationId,
        string $amount,
        string $idempotencyKey,
    ): CreditBalancePaymentResult;
}
