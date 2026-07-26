<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Integrations;

use App\Modules\Payment\Application\Contracts\RelationPaymentPort;
use App\Modules\Payment\Application\DTOs\RelationPaymentSnapshot;
use App\Modules\Payment\Domain\DTOs\PaymentAllocation;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use Carbon\CarbonImmutable;

/** Adaptador fail-closed mientras M10 no publique su frontera de pagos. */
final class UnavailableRelationPaymentPort implements RelationPaymentPort
{
    public function normalizeReference(string $rawReference): string
    {
        throw PaymentDomainException::relationContractUnavailable();
    }

    public function lockByExactReference(string $normalizedReference, int $branchId): ?RelationPaymentSnapshot
    {
        throw PaymentDomainException::relationContractUnavailable();
    }

    public function lockById(string $relationId, int $branchId, int $distributorId): RelationPaymentSnapshot
    {
        throw PaymentDomainException::relationContractUnavailable();
    }

    public function apply(
        RelationPaymentSnapshot $relation,
        PaymentAllocation $allocation,
        string $paymentAllocationId,
        CarbonImmutable $effectiveAt,
    ): void {
        throw PaymentDomainException::relationContractUnavailable();
    }
}
