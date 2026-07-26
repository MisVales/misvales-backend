<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Integrations;

use App\Modules\ExcessBalance\Application\Contracts\CreditBalanceApplicationPort;
use App\Modules\ExcessBalance\Application\DTOs\CreditBalancePaymentResult;
use App\Modules\ExcessBalance\Application\DTOs\LockedRelation;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;

final class UnavailableCreditBalanceApplicationPort implements CreditBalanceApplicationPort
{
    public function lockRelationAndCredit(
        string $relationId,
        int $distributorId,
        int $branchId,
    ): LockedRelation {
        throw ExcessBalanceException::integrationUnavailable();
    }

    public function apply(
        LockedRelation $relation,
        string $excessApplicationId,
        string $paymentAllocationId,
        string $amount,
        string $idempotencyKey,
    ): CreditBalancePaymentResult {
        throw ExcessBalanceException::integrationUnavailable();
    }
}
