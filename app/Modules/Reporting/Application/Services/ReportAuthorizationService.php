<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;

final class ReportAuthorizationService
{
    public function role(User $actor): RoleCode
    {
        $actor->loadMissing('role.permissions', 'branch');
        if ($actor->state !== AccountState::ACTIVE || ! $actor->role->is_active) {
            throw ReportingException::accessDenied();
        }

        return $actor->role->code;
    }

    public function assertCatalogAccess(User $actor): RoleCode
    {
        $role = $this->role($actor);
        $permission = match ($role) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => PermissionCode::REPORTS_VIEW_GLOBAL,
            RoleCode::SUCURSAL_MANAGER => PermissionCode::REPORTS_VIEW_BRANCH,
            RoleCode::COORDINATOR => PermissionCode::REPORTS_VIEW_ASSIGNED,
            RoleCode::DISTRIBUTOR => PermissionCode::REPORTS_VIEW_OWN,
            default => throw ReportingException::accessDenied(),
        };

        if (! $actor->role->permissions->contains(
            static fn (object $item): bool => $item->code === $permission,
        )) {
            throw ReportingException::accessDenied();
        }

        return $role;
    }

    public function assertReportAccess(User $actor, ReportDefinition $definition): RoleCode
    {
        $role = $this->assertCatalogAccess($actor);
        if (! $definition->permits($role)) {
            throw ReportingException::accessDenied();
        }

        return $role;
    }
}
