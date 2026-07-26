<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskProfileStatus;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;

final class CreateDistributorRiskProfile
{
    public function create(User $distributor): DistributorRiskProfile
    {
        if ($distributor->branch_id === null) {
            throw new RiskDelinquencyException('DELINQUENCY_STATE_CONFLICT', 'La distribuidora no tiene sucursal vigente.', 409);
        }

        return DistributorRiskProfile::query()->firstOrCreate(
            ['distributor_id' => $distributor->id],
            [
                'current_branch_id' => $distributor->branch_id,
                'current_coordinator_id' => $distributor->coordinator_id,
                'consecutive_breaches' => 0,
                'overdue_balance' => '0.0000',
                'delinquency_status' => DelinquencyStatus::NOT_DELINQUENT,
                'blocked_for_new_vouchers' => false,
                'profile_status' => RiskProfileStatus::CURRENT,
                'lock_version' => 1,
            ],
        );
    }
}
