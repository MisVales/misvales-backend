<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Construye el contexto desde la cuenta autenticada y valida su vigencia. */
final class ActorContextFactory
{
    public function fromUser(User $user): ActorContext
    {
        $user->loadMissing('role.permissions', 'branch');

        if (
            $user->state !== AccountState::ACTIVE
            || ! $user->role->is_active
            || ($user->branch_id !== null && ($user->branch === null || ! $user->branch->is_active))
        ) {
            throw OnboardingDomainException::authorizationDenied();
        }

        return new ActorContext(
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
