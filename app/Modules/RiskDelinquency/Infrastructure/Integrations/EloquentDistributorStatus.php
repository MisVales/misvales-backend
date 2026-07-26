<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Application\Contracts\DistributorStatusPort;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;

final class EloquentDistributorStatus implements DistributorStatusPort
{
    public function lock(int $distributorId): User
    {
        $distributor = User::query()->with('role')->lockForUpdate()->find($distributorId);
        if ($distributor === null || $distributor->role_code !== RoleCode::DISTRIBUTOR->value) {
            throw RiskDelinquencyException::sourceInconsistent();
        }

        return $distributor;
    }
}
