<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;

final class RefundRequestPolicy
{
    public function view(User $actor, RefundRequestModel $request): bool
    {
        $actor->loadMissing('role');

        return match ($actor->role->code) {
            RoleCode::GENERAL_MANAGER, RoleCode::ADMINISTRATOR => true,
            RoleCode::SUCURSAL_MANAGER, RoleCode::CASHIER => $actor->branch_id === $request->branch_id,
            RoleCode::COORDINATOR => DistributorAccessLink::query()
                ->where('user_id', $request->distributor_id)
                ->where('coordinator_user_id', $actor->id)
                ->where('branch_id', $request->branch_id)
                ->exists(),
            RoleCode::DISTRIBUTOR => $actor->id === $request->distributor_id,
            default => false,
        };
    }

    public function authorize(User $actor, RefundRequestModel $request): bool
    {
        $actor->loadMissing('role');

        return $actor->role->code === RoleCode::GENERAL_MANAGER
            || ($actor->role->code === RoleCode::SUCURSAL_MANAGER
                && $actor->branch_id === $request->branch_id);
    }

    public function complete(User $actor, RefundRequestModel $request): bool
    {
        $actor->loadMissing('role');

        return $actor->role->code === RoleCode::CASHIER
            && $actor->branch_id === $request->branch_id;
    }
}
