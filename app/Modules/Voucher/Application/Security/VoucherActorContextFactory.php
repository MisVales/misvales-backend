<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/** Resuelve rol, permisos y sucursal exclusivamente desde M01. */
final class VoucherActorContextFactory
{
    public function fromUser(User $user): VoucherActorContext
    {
        $user->loadMissing('role.permissions', 'branch');
        if (
            $user->state !== AccountState::ACTIVE
            || ! $user->role->is_active
            || ($user->branch_id !== null && ($user->branch === null || ! $user->branch->is_active))
        ) {
            throw VoucherDomainException::scopeDenied();
        }

        return new VoucherActorContext(
            userId: (int) $user->id,
            publicId: (string) $user->public_id,
            role: $user->role->code,
            branchId: $user->branch_id === null ? null : (int) $user->branch_id,
            branchPublicId: $user->branch_public_id,
            permissions: $user->role->permissions
                ->where('is_active', true)
                ->pluck('code')
                ->map(static fn (mixed $code): string => $code instanceof PermissionCode ? $code->value : (string) $code)
                ->values()
                ->all(),
        );
    }
}
