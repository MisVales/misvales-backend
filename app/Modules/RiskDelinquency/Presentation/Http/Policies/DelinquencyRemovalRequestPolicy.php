<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Application\Services\RiskAccessService;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;

final readonly class DelinquencyRemovalRequestPolicy
{
    public function __construct(private RiskAccessService $access) {}

    public function view(User $actor, DelinquencyRemovalRequest $request): bool
    {
        return in_array($actor->role_code, [
            RoleCode::COORDINATOR->value,
            RoleCode::SUCURSAL_MANAGER->value,
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value,
        ], true) && $this->access->scopeProfiles(DistributorRiskProfile::query(), $actor)
            ->where('distributor_id', $request->distributor_id)
            ->exists();
    }

    public function decide(User $actor, DelinquencyRemovalRequest $request): bool
    {
        if (! $this->view($actor, $request)) {
            return false;
        }
        $permission = $actor->role_code === RoleCode::GENERAL_MANAGER->value
            ? PermissionCode::DELINQUENCY_REMOVAL_DECIDE_GLOBAL
            : PermissionCode::DELINQUENCY_REMOVAL_DECIDE_BRANCH;

        return in_array($actor->role_code, [RoleCode::GENERAL_MANAGER->value, RoleCode::SUCURSAL_MANAGER->value], true)
            && $this->access->permitted($actor, $permission);
    }
}
