<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Database\Eloquent\Builder;

final class ExcessScopeService
{
    /** @param Builder<ExcessBalanceModel> $query
     * @return Builder<ExcessBalanceModel>
     */
    public function balances(Builder $query, User $actor): Builder
    {
        $this->assertPermission($actor, match ($this->role($actor)) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => PermissionCode::EXCESS_BALANCES_VIEW_GLOBAL,
            RoleCode::SUCURSAL_MANAGER, RoleCode::CASHIER => PermissionCode::EXCESS_BALANCES_VIEW_BRANCH,
            RoleCode::COORDINATOR => PermissionCode::EXCESS_BALANCES_VIEW_ASSIGNED,
            RoleCode::DISTRIBUTOR => PermissionCode::EXCESS_BALANCES_VIEW_OWN,
            default => throw ExcessBalanceException::authorizationDenied(),
        });

        return match ($this->role($actor)) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => $query,
            RoleCode::SUCURSAL_MANAGER, RoleCode::CASHIER => $query->where('branch_id', $actor->branch_id),
            RoleCode::COORDINATOR => $query->whereIn(
                'distributor_id',
                DistributorAccessLink::query()
                    ->select('user_id')
                    ->where('coordinator_user_id', $actor->id)
                    ->where('branch_id', $actor->branch_id),
            ),
            RoleCode::DISTRIBUTOR => $query->where('distributor_id', $actor->id),
            default => throw ExcessBalanceException::authorizationDenied(),
        };
    }

    /** @param Builder<RefundRequestModel> $query
     * @return Builder<RefundRequestModel>
     */
    public function refunds(Builder $query, User $actor): Builder
    {
        $this->assertPermission($actor, match ($this->role($actor)) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => PermissionCode::REFUNDS_VIEW_GLOBAL,
            RoleCode::SUCURSAL_MANAGER, RoleCode::CASHIER => PermissionCode::REFUNDS_VIEW_BRANCH,
            RoleCode::COORDINATOR => PermissionCode::REFUNDS_VIEW_ASSIGNED,
            RoleCode::DISTRIBUTOR => PermissionCode::REFUNDS_VIEW_OWN,
            default => throw ExcessBalanceException::authorizationDenied(),
        });

        return match ($this->role($actor)) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => $query,
            RoleCode::SUCURSAL_MANAGER, RoleCode::CASHIER => $query->where('branch_id', $actor->branch_id),
            RoleCode::COORDINATOR => $query->whereIn(
                'distributor_id',
                DistributorAccessLink::query()
                    ->select('user_id')
                    ->where('coordinator_user_id', $actor->id)
                    ->where('branch_id', $actor->branch_id),
            ),
            RoleCode::DISTRIBUTOR => $query->where('distributor_id', $actor->id),
            default => throw ExcessBalanceException::authorizationDenied(),
        };
    }

    public function assertOwner(User $actor, int $distributorId): void
    {
        if ($this->role($actor) !== RoleCode::DISTRIBUTOR
            || ! $this->hasPermission($actor, PermissionCode::EXCESS_BALANCES_DECIDE_OWN)
            || $actor->id !== $distributorId) {
            throw ExcessBalanceException::notFound();
        }
    }

    public function assertManager(User $actor, int $branchId): void
    {
        $role = $this->role($actor);
        $allowed = ($role === RoleCode::GENERAL_MANAGER
                && $this->hasPermission($actor, PermissionCode::REFUNDS_AUTHORIZE_GLOBAL))
            || ($role === RoleCode::SUCURSAL_MANAGER
                && $this->hasPermission($actor, PermissionCode::REFUNDS_AUTHORIZE_BRANCH)
                && $actor->branch_id === $branchId);
        if (! $allowed) {
            throw ExcessBalanceException::authorizationDenied();
        }
    }

    public function assertCashier(User $actor, int $branchId): void
    {
        if ($this->role($actor) !== RoleCode::CASHIER
            || ! $this->hasPermission($actor, PermissionCode::REFUNDS_COMPLETE)
            || $actor->branch_id !== $branchId) {
            throw ExcessBalanceException::authorizationDenied();
        }
    }

    private function role(User $actor): RoleCode
    {
        $actor->loadMissing('role');

        return $actor->role->code;
    }

    private function assertPermission(User $actor, PermissionCode $permission): void
    {
        if (! $this->hasPermission($actor, $permission)) {
            throw ExcessBalanceException::authorizationDenied();
        }
    }

    private function hasPermission(User $actor, PermissionCode $permission): bool
    {
        $actor->loadMissing('role.permissions');

        return $actor->role->permissions
            ->where('is_active', true)
            ->contains(fn (mixed $value): bool => $value->code === $permission);
    }
}
