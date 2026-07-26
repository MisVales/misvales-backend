<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Application\Services\RiskAccessService;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;

final readonly class RiskAlertPolicy
{
    public function __construct(private RiskAccessService $access) {}

    public function view(User $actor, RiskAlert $alert): bool
    {
        return $this->visible($actor, $alert);
    }

    public function applyDelinquency(User $actor, RiskAlert $alert): bool
    {
        if (! $this->visible($actor, $alert)) {
            return false;
        }
        $permission = $actor->role_code === RoleCode::GENERAL_MANAGER->value
            ? PermissionCode::DELINQUENCY_APPLY_GLOBAL
            : PermissionCode::DELINQUENCY_APPLY_BRANCH;

        return in_array($actor->role_code, [RoleCode::GENERAL_MANAGER->value, RoleCode::SUCURSAL_MANAGER->value], true)
            && $this->access->permitted($actor, $permission);
    }

    private function visible(User $actor, RiskAlert $alert): bool
    {
        return in_array($actor->role_code, [
            RoleCode::COORDINATOR->value,
            RoleCode::SUCURSAL_MANAGER->value,
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value,
        ], true) && $this->access->scopeProfiles(DistributorRiskProfile::query(), $actor)
            ->where('distributor_id', $alert->distributor_id)
            ->exists();
    }
}
