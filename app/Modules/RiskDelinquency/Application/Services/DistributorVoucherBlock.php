<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Modules\RiskDelinquency\Application\Contracts\CanDistributorIssueVoucher;
use App\Modules\RiskDelinquency\Application\DTOs\VoucherIssuanceDecision;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;

final class DistributorVoucherBlock implements CanDistributorIssueVoucher
{
    public function check(int $distributorId): VoucherIssuanceDecision
    {
        $profile = DistributorRiskProfile::query()->where('distributor_id', $distributorId)->first();
        if ($profile === null) {
            return new VoucherIssuanceDecision(true, null, 0, null);
        }
        $consistent = $profile->blocked_for_new_vouchers === $profile->delinquency_status->blocksVoucherIssuance();
        if (! $consistent) {
            return new VoucherIssuanceDecision(false, 'DELINQUENCY_STATE_CONFLICT', $profile->lock_version, $profile->delinquency_applied_at);
        }

        return new VoucherIssuanceDecision(
            ! $profile->blocked_for_new_vouchers,
            $profile->blocked_for_new_vouchers ? 'DISTRIBUTOR_DELINQUENT' : null,
            $profile->lock_version,
            $profile->delinquency_applied_at,
        );
    }

    public function assertAllowed(int $distributorId): void
    {
        $decision = $this->check($distributorId);
        if (! $decision->allowed) {
            throw new RiskDelinquencyException(
                $decision->restrictionCode ?? 'DELINQUENCY_STATE_CONFLICT',
                'La distribuidora tiene restringido el otorgamiento de nuevos vales.',
                409,
            );
        }
    }
}
