<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;

final class ExcessBalancePolicy
{
    public function view(User $actor, ExcessBalanceModel $balance): bool
    {
        $actor->loadMissing('role');

        return match ($actor->role->code) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => true,
            RoleCode::SUCURSAL_MANAGER, RoleCode::CASHIER => $actor->branch_id === $balance->branch_id,
            RoleCode::COORDINATOR => DistributorAccessLink::query()
                ->where('user_id', $balance->distributor_id)
                ->where('coordinator_user_id', $actor->id)
                ->where('branch_id', $balance->branch_id)
                ->exists(),
            RoleCode::DISTRIBUTOR => $actor->id === $balance->distributor_id,
            default => false,
        };
    }

    public function decide(User $actor, ExcessBalanceModel $balance): bool
    {
        $actor->loadMissing('role');

        return $actor->role->code === RoleCode::DISTRIBUTOR
            && $actor->id === $balance->distributor_id;
    }
}
