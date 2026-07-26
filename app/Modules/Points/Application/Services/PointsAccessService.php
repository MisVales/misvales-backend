<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;

/** Matriz de mínimo privilegio y alcance organizacional para M13. */
final class PointsAccessService
{
    public function assertCanViewDistributor(User $actor, User $distributor): void
    {
        $role = $actor->role_code;
        [$allowed, $permission] = match ($role) {
            RoleCode::DISTRIBUTOR->value => [$actor->is($distributor), PermissionCode::POINTS_VIEW_OWN],
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value => [true, PermissionCode::POINTS_VIEW_GLOBAL],
            RoleCode::SUCURSAL_MANAGER->value => [
                $actor->branch_id !== null && $actor->branch_id === $distributor->branch_id,
                PermissionCode::POINTS_VIEW_BRANCH,
            ],
            RoleCode::COORDINATOR->value => [
                $distributor->coordinator_id === $actor->id,
                PermissionCode::POINTS_VIEW_ASSIGNED,
            ],
            default => [false, null],
        };

        if (! $allowed || ! $permission instanceof PermissionCode || ! $this->hasPermission($actor, $permission)) {
            throw new PointsDomainException(
                'REDEMPTION_OUT_OF_SCOPE',
                'El recurso de puntos no existe dentro del alcance autorizado.',
                404,
            );
        }
    }

    public function assertCanDecide(User $actor, User $distributor): void
    {
        $permission = $actor->role_code === RoleCode::GENERAL_MANAGER->value
            ? PermissionCode::POINT_REDEMPTIONS_DECIDE_GLOBAL
            : PermissionCode::POINT_REDEMPTIONS_DECIDE_BRANCH;
        $allowed = ($actor->role_code === RoleCode::GENERAL_MANAGER->value
            || ($actor->role_code === RoleCode::SUCURSAL_MANAGER->value
                && $actor->branch_id !== null
                && $actor->branch_id === $distributor->branch_id))
            && $this->hasPermission($actor, $permission);

        if (! $allowed) {
            throw new PointsDomainException(
                'REDEMPTION_OUT_OF_SCOPE',
                'La solicitud no existe dentro del alcance de decisión.',
                404,
            );
        }

        if ($actor->is($distributor)) {
            throw new PointsDomainException(
                'SEPARATION_OF_DUTIES_VIOLATION',
                'Quien solicita no puede decidir el mismo canje.',
                403,
            );
        }
    }

    public function assertGeneralManager(User $actor): void
    {
        if ($actor->role_code !== RoleCode::GENERAL_MANAGER->value
            || ! $this->hasPermission($actor, PermissionCode::REDEMPTION_PERIOD_MANAGE)) {
            throw new PointsDomainException(
                'REDEMPTION_OUT_OF_SCOPE',
                'La operación requiere alcance de gerente general.',
                403,
            );
        }
    }

    public function assertCanViewRuns(User $actor): void
    {
        if (! in_array($actor->role_code, [RoleCode::GENERAL_MANAGER->value, RoleCode::ADMINISTRATOR->value], true)
            || ! $this->hasPermission($actor, PermissionCode::POINTS_RUNS_VIEW_GLOBAL)) {
            throw new PointsDomainException('REDEMPTION_OUT_OF_SCOPE', 'La ejecución no existe en el alcance.', 404);
        }
    }

    public function hasPermission(User $actor, PermissionCode $permission): bool
    {
        return $actor->role()->whereHas(
            'permissions',
            fn ($query) => $query->where('permissions.code', $permission->value)
                ->where('permissions.is_active', true),
        )->exists();
    }
}
