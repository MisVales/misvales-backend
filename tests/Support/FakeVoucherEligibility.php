<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Voucher\Application\Contracts\VoucherEligibilityPort;
use App\Modules\Voucher\Application\DTOs\VoucherEligibility;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

final readonly class FakeVoucherEligibility implements VoucherEligibilityPort
{
    public function __construct(private VoucherEligibility $eligibility) {}

    public function forRelease(VoucherModel $voucher): VoucherEligibility
    {
        return $this->eligibility;
    }

    public function forRejection(VoucherModel $voucher): VoucherEligibility
    {
        return $this->eligibility;
    }

    public function forFulfillment(VoucherModel $voucher): VoucherEligibility
    {
        return $this->eligibility;
    }
}
