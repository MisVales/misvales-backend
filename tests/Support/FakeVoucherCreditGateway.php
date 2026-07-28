<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Credit\Application\Contracts\CreditVoucherGateway;
use App\Modules\Credit\Application\DTOs\CreditEligibility;
use App\Modules\Credit\Application\DTOs\VoucherCapitalUsage;
use App\Modules\Credit\Domain\ValueObjects\Money;

final class FakeVoucherCreditGateway implements CreditVoucherGateway
{
    public int $released = 0;

    /** @var array<string, VoucherCapitalUsage> */
    public array $fulfilled = [];

    public function eligibility(int $distributorId, Money $capital): CreditEligibility
    {
        return new CreditEligibility(true, new Money('100000.00'), null, null);
    }

    public function lockedEligibility(int $distributorId, Money $capital): CreditEligibility
    {
        return $this->eligibility($distributorId, $capital);
    }

    public function bindRestriction(
        int $distributorId,
        string $voucherId,
        Money $capital,
        ?int $actorUserId = null,
    ): void {}

    public function releaseRestriction(
        int $distributorId,
        string $voucherId,
        ?int $actorUserId = null,
    ): void {
        $this->released++;
    }

    public function applyFulfilledVoucher(VoucherCapitalUsage $usage): string
    {
        $this->fulfilled[$usage->voucherId] ??= $usage;

        return 'movement-'.$usage->voucherId;
    }
}
