<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Voucher\Application\Contracts\VoucherEligibilityPort;
use App\Modules\Voucher\Application\DTOs\VoucherEligibility;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

/** Fail-closed hasta integrar los contratos propietarios de M05/M08. */
final class UnavailableVoucherEligibilityPort implements VoucherEligibilityPort
{
    public function forRelease(VoucherModel $voucher): VoucherEligibility
    {
        throw VoucherDomainException::dependencyUnavailable('M05_M08_RELEASE_ELIGIBILITY');
    }

    public function forFulfillment(VoucherModel $voucher): VoucherEligibility
    {
        throw VoucherDomainException::dependencyUnavailable('M05_M08_FULFILLMENT_ELIGIBILITY');
    }

    public function forRejection(VoucherModel $voucher): VoucherEligibility
    {
        throw VoucherDomainException::dependencyUnavailable('M05_M08_REJECTION_ELIGIBILITY');
    }
}
