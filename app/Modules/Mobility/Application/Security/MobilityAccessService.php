<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;
use Illuminate\Database\Eloquent\Builder;

final class MobilityAccessService
{
    public function permitted(User $actor, PermissionCode $permission): bool
    {
        return $actor->role()->whereHas(
            'permissions',
            fn ($query) => $query->where('permissions.code', $permission->value)
                ->where('permissions.is_active', true),
        )->exists();
    }

    /**
     * @param  Builder<ClientTransfer>  $query
     * @return Builder<ClientTransfer>
     */
    public function scopeTransfers(Builder $query, User $actor): Builder
    {
        return match ($actor->role_code) {
            RoleCode::DISTRIBUTOR->value => $this->permitted($actor, PermissionCode::MOBILITY_VIEW_OWN)
                ? $query->where(fn ($q) => $q->where('origin_distributor_id', $actor->public_id)
                    ->orWhere('recipient_distributor_id', $actor->public_id))
                : $query->whereRaw('1 = 0'),
            RoleCode::COORDINATOR->value => $this->permitted($actor, PermissionCode::MOBILITY_VIEW_ASSIGNED)
                ? $query->where('origin_coordinator_id', $actor->id)
                : $query->whereRaw('1 = 0'),
            RoleCode::SUCURSAL_MANAGER->value => $this->permitted($actor, PermissionCode::MOBILITY_VIEW_BRANCH)
                ? $query->where('origin_branch_id', $actor->branch_id)
                : $query->whereRaw('1 = 0'),
            RoleCode::GENERAL_MANAGER->value,
            RoleCode::ADMINISTRATOR->value => $this->permitted($actor, PermissionCode::MOBILITY_VIEW_GLOBAL)
                ? $query
                : $query->whereRaw('1 = 0'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function assertTransferVisible(User $actor, ClientTransfer $transfer): void
    {
        if (! $this->scopeTransfers(ClientTransfer::query(), $actor)->whereKey($transfer->id)->exists()) {
            throw MobilityException::scopeDenied();
        }
    }

    public function assertOriginDistributor(User $actor, string $originId): void
    {
        if ($actor->role_code !== RoleCode::DISTRIBUTOR->value
            || $actor->public_id !== $originId
            || ! $this->permitted($actor, PermissionCode::MOBILITY_TRANSFER_CREATE_OWN)) {
            throw MobilityException::scopeDenied();
        }
    }

    public function assertRecipient(User $actor, ClientTransfer $transfer): void
    {
        if ($actor->role_code !== RoleCode::DISTRIBUTOR->value
            || $actor->public_id !== $transfer->recipient_distributor_id
            || ! $this->permitted($actor, PermissionCode::MOBILITY_TRANSFER_RESPOND_OWN)) {
            throw MobilityException::scopeDenied();
        }
    }

    public function assertOriginCoordinator(User $actor, ClientTransfer $transfer): void
    {
        if ($actor->role_code !== RoleCode::COORDINATOR->value
            || $actor->id !== $transfer->origin_coordinator_id
            || $actor->branch_id !== $transfer->origin_branch_id
            || ! $this->permitted($actor, PermissionCode::MOBILITY_TRANSFER_AUTHORIZE_ASSIGNED)) {
            throw MobilityException::scopeDenied();
        }
    }

    public function assertManager(User $actor, ?int $branchId, PermissionCode $branch, PermissionCode $global): void
    {
        $allowed = ($actor->role_code === RoleCode::GENERAL_MANAGER->value && $this->permitted($actor, $global))
            || ($actor->role_code === RoleCode::SUCURSAL_MANAGER->value
                && $branchId !== null
                && $actor->branch_id === $branchId
                && $this->permitted($actor, $branch));
        if (! $allowed) {
            throw MobilityException::scopeDenied();
        }
    }

    public function isReadOnlyAdministrator(User $actor): bool
    {
        return $actor->role_code === RoleCode::ADMINISTRATOR->value;
    }
}
