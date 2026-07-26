<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\RiskDelinquency\Application\Contracts\OrganizationScopePort;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;

final class EloquentOrganizationScope implements OrganizationScopePort
{
    public function assertManagerScope(User $actor, User $distributor): void
    {
        $allowed = $actor->role_code === RoleCode::GENERAL_MANAGER->value
            || ($actor->role_code === RoleCode::SUCURSAL_MANAGER->value
                && $actor->branch_id !== null
                && $actor->branch_id === $distributor->branch_id);
        if (! $allowed) {
            throw RiskDelinquencyException::scopeDenied();
        }
    }

    public function assertResponsibleCoordinator(User $actor, User $distributor): void
    {
        if ($actor->role_code !== RoleCode::COORDINATOR->value || $distributor->coordinator_id !== $actor->id) {
            throw new RiskDelinquencyException('COORDINATOR_NOT_ASSIGNED', 'La coordinación no está asignada a la distribuidora.', 403);
        }
    }
}
