<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;

final readonly class DataChangeRequestPolicy
{
    public function __construct(private VoucherActorContextFactory $contexts) {}

    public function viewAny(User $user): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::VOUCHER_MODIFICATIONS_VIEW->value);
    }

    public function create(User $user): bool
    {
        $actor = $this->contexts->fromUser($user);

        return $actor->role === RoleCode::CASHIER
            && $actor->hasPermission(PermissionCode::VOUCHER_MODIFICATIONS_REQUEST->value);
    }

    public function apply(User $user): bool
    {
        $actor = $this->contexts->fromUser($user);

        return $actor->role === RoleCode::CASHIER
            && $actor->hasPermission(PermissionCode::VOUCHER_MODIFICATIONS_APPLY->value);
    }

    public function decide(User $user): bool
    {
        $actor = $this->contexts->fromUser($user);

        return in_array($actor->role, [
            RoleCode::COORDINATOR,
            RoleCode::SUCURSAL_MANAGER,
            RoleCode::GENERAL_MANAGER,
        ], true)
            && $actor->hasPermission(PermissionCode::VOUCHER_MODIFICATIONS_DECIDE->value);
    }
}
