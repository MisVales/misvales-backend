<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use Illuminate\Database\Eloquent\Builder;

final class CreditScopeService
{
    public function assertCanReadDistributor(User $actor, User $distributor): void
    {
        $role = $this->role($actor);
        $allowed = match ($role) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => true,
            RoleCode::SUCURSAL_MANAGER => $actor->branch_id === $distributor->branch_id,
            RoleCode::COORDINATOR => $actor->branch_id === $distributor->branch_id
                && $this->link($distributor)?->coordinator_user_id === $actor->id,
            RoleCode::DISTRIBUTOR => $actor->is($distributor),
            default => false,
        };

        $this->assert($allowed);
    }

    public function assertCanRequest(User $actor, User $distributor): void
    {
        $this->assert($this->role($actor) === RoleCode::DISTRIBUTOR && $actor->is($distributor));
    }

    public function assertCanReview(User $actor, CreditIncreaseRequestModel $request): void
    {
        $this->assert(
            $this->role($actor) === RoleCode::COORDINATOR
            && $actor->branch_id === $request->branch_id
            && $actor->id === $request->coordinator_id,
        );
    }

    public function assertCanDecide(User $actor, CreditIncreaseRequestModel $request): void
    {
        $role = $this->role($actor);
        $this->assert(
            $role === RoleCode::GENERAL_MANAGER
            || ($role === RoleCode::SUCURSAL_MANAGER && $actor->branch_id === $request->branch_id),
        );
    }

    /**
     * @param  Builder<CreditIncreaseRequestModel>  $query
     * @return Builder<CreditIncreaseRequestModel>
     */
    public function scopeIncreaseRequests(Builder $query, User $actor): Builder
    {
        return match ($this->role($actor)) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => $query,
            RoleCode::SUCURSAL_MANAGER => $query->where('branch_id', $actor->branch_id),
            RoleCode::COORDINATOR => $query->where('branch_id', $actor->branch_id)->where('coordinator_id', $actor->id),
            RoleCode::DISTRIBUTOR => $query->where('distributor_id', $actor->id),
            default => throw new CreditRuleViolation('El actor no puede consultar solicitudes de incremento.', 'AUTH_SCOPE_DENIED', 403),
        };
    }

    private function role(User $actor): RoleCode
    {
        $actor->loadMissing('role');

        return $actor->role->code;
    }

    private function link(User $distributor): ?DistributorAccessLink
    {
        return DistributorAccessLink::query()->where('user_id', $distributor->id)->first();
    }

    private function assert(bool $condition): void
    {
        if (! $condition) {
            throw new CreditRuleViolation('El actor no tiene alcance para esta operación.', 'AUTH_SCOPE_DENIED', 403);
        }
    }
}
