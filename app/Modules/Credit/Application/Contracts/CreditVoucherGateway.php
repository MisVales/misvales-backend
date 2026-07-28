<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Contracts;

use App\Modules\Credit\Application\DTOs\CreditEligibility;
use App\Modules\Credit\Application\DTOs\VoucherCapitalUsage;
use App\Modules\Credit\Domain\ValueObjects\Money;

interface CreditVoucherGateway
{
    public function eligibility(int $distributorId, Money $capital): CreditEligibility;

    /**
     * Resuelve la elegibilidad manteniendo bloqueadas la línea y su restricción
     * durante la transacción exterior de generación del vale.
     */
    public function lockedEligibility(int $distributorId, Money $capital): CreditEligibility;

    public function bindRestriction(int $distributorId, string $voucherId, Money $capital, ?int $actorUserId = null): void;

    public function releaseRestriction(int $distributorId, string $voucherId, ?int $actorUserId = null): void;

    public function applyFulfilledVoucher(VoucherCapitalUsage $usage): string;
}
