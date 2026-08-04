<?php

namespace App\Policies;

use App\Models\User;

class PermissionPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('roles.manage_permissions');
    }
}
