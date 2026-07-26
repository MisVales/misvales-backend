<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use Illuminate\Database\Eloquent\Builder;

final class RiskAccessService
{
    /**
     * @param  Builder<DistributorRiskProfile>  $query
     * @return Builder<DistributorRiskProfile>
     */
    public function scopeProfiles(Builder $query, User $actor): Builder
    {
        return match ($actor->role_code) {
            RoleCode::DISTRIBUTOR->value => $this->permitted($actor, PermissionCode::RISK_VIEW_OWN)
                ? $query->where('distributor_id', $actor->id)
                : $query->whereRaw('1 = 0'),
            RoleCode::COORDINATOR->value => $this->permitted($actor, PermissionCode::RISK_VIEW_ASSIGNED)
                ? $query->where('current_coordinator_id', $actor->id)
                : $query->whereRaw('1 = 0'),
            RoleCode::SUCURSAL_MANAGER->value => $this->permitted($actor, PermissionCode::RISK_VIEW_BRANCH)
                ? $query->where('current_branch_id', $actor->branch_id)
                : $query->whereRaw('1 = 0'),
            RoleCode::CASHIER->value => $this->permitted($actor, PermissionCode::RISK_BLOCK_VIEW_BRANCH)
                ? $query->where('current_branch_id', $actor->branch_id)
                : $query->whereRaw('1 = 0'),
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value => $this->permitted($actor, PermissionCode::RISK_VIEW_GLOBAL)
                ? $query
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function assertProfile(User $actor, DistributorRiskProfile $profile): void
    {
        if (! $this->scopeProfiles(DistributorRiskProfile::query(), $actor)->whereKey($profile->id)->exists()) {
            throw RiskDelinquencyException::scopeDenied();
        }
    }

    public function assertDetailedProfile(User $actor, DistributorRiskProfile $profile): void
    {
        $this->assertProfile($actor, $profile);
        if (! in_array($actor->role_code, [
            RoleCode::COORDINATOR->value,
            RoleCode::SUCURSAL_MANAGER->value,
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value,
        ], true)) {
            throw RiskDelinquencyException::scopeDenied();
        }
    }

    public function assertRemovalView(User $actor): void
    {
        if (! in_array($actor->role_code, [
            RoleCode::COORDINATOR->value,
            RoleCode::SUCURSAL_MANAGER->value,
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value,
        ], true)) {
            throw RiskDelinquencyException::scopeDenied();
        }
    }

    public function permitted(User $actor, PermissionCode $permission): bool
    {
        return $actor->role()->whereHas(
            'permissions',
            fn ($query) => $query->where('permissions.code', $permission->value)->where('permissions.is_active', true),
        )->exists();
    }
}
