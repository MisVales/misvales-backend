<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;

/**
 * Revokes active access by bumping credential and context versions.
 */
final class ImmediateAccessRevoker
{
    public function revoke(User $user): void
    {
        $user->forceFill([
            'credential_version' => ((int) $user->credential_version) + 1,
            'context_version' => ((int) $user->context_version) + 1,
        ])->save();
    }
}
