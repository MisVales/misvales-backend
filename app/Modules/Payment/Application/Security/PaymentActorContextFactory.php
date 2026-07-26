<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/** Rechaza cuentas, roles o sucursales inactivos antes de crear el contexto M11. */
final class PaymentActorContextFactory
{
    public function fromUser(User $user): PaymentActorContext
    {
        $user->loadMissing('role.permissions', 'branch');
        if (
            $user->state !== AccountState::ACTIVE
            || ! $user->role->is_active
            || ($user->branch_id !== null && ($user->branch === null || ! $user->branch->is_active))
        ) {
            throw PaymentDomainException::authorizationDenied();
        }

        return new PaymentActorContext(
            userId: (int) $user->getKey(),
            publicId: $user->public_id,
            role: $user->role->code,
            branchId: $user->branch_id === null ? null : (int) $user->branch_id,
            coordinatorId: $user->coordinator_id === null ? null : (int) $user->coordinator_id,
            permissions: $user->role->permissions
                ->where('is_active', true)
                ->pluck('code')
                ->map(static fn (mixed $code): string => $code instanceof PermissionCode ? $code->value : (string) $code)
                ->values()
                ->all(),
        );
    }
}
