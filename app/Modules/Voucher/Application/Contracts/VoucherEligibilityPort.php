<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Contracts;

use App\Modules\Voucher\Application\DTOs\VoucherEligibility;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

/** Frontera de M09 hacia asociación, distribuidora, producto y cuenta vigentes. */
interface VoucherEligibilityPort
{
    public function forRelease(VoucherModel $voucher): VoucherEligibility;

    public function forRejection(VoucherModel $voucher): VoucherEligibility;

    public function forFulfillment(VoucherModel $voucher): VoucherEligibility;
}
