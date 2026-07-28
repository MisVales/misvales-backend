<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Voucher\Application\Security\VoucherActorContextFactory;

final readonly class VoucherPolicy
{
    public function __construct(private VoucherActorContextFactory $contexts) {}

    public function viewAny(User $user): bool
    {
        return $this->contexts->fromUser($user)->hasPermission(PermissionCode::VOUCHERS_VIEW->value);
    }

    public function generate(User $user): bool
    {
        $actor = $this->contexts->fromUser($user);

        return $actor->role === RoleCode::DISTRIBUTOR
            && $actor->hasPermission(PermissionCode::VOUCHERS_GENERATE->value);
    }

    public function openAtCounter(User $user): bool
    {
        return $this->cashierHas($user, PermissionCode::VOUCHERS_OPEN_AT_COUNTER);
    }

    public function release(User $user): bool
    {
        return $this->cashierHas($user, PermissionCode::VOUCHERS_RELEASE);
    }

    public function reject(User $user): bool
    {
        return $this->cashierHas($user, PermissionCode::VOUCHERS_REJECT);
    }

    public function fulfill(User $user): bool
    {
        return $this->cashierHas($user, PermissionCode::VOUCHERS_FULFILL);
    }

    private function cashierHas(User $user, PermissionCode $permission): bool
    {
        $actor = $this->contexts->fromUser($user);

        return $actor->role === RoleCode::CASHIER
            && $actor->branchId !== null
            && $actor->hasPermission($permission->value);
    }
}
