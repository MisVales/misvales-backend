<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;

/** Construye el contexto de M06 desde una cuenta activa de M01. */
final class ClientActorContextFactory
{
    public function fromUser(User $user): ClientActorContext
    {
        $user->loadMissing('role.permissions', 'branch');

        if (
            $user->state !== AccountState::ACTIVE
            || ! $user->role->is_active
            || ($user->branch_id !== null && ($user->branch === null || ! $user->branch->is_active))
        ) {
            throw ClientDomainException::authorizationDenied();
        }

        return new ClientActorContext(
            userId: (int) $user->getKey(),
            role: $user->role->code,
            branchId: $user->branch_id === null ? null : (int) $user->branch_id,
            permissions: $user->role->permissions
                ->where('is_active', true)
                ->pluck('code')
                ->map(static fn (mixed $code): string => $code instanceof PermissionCode ? $code->value : (string) $code)
                ->values()
                ->all(),
        );
    }
}
