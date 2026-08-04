<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserRoleScope;

class UserRoleScopePolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('roles.assign');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('roles.assign');
    }

    public function delete(User $user, UserRoleScope $assignment): bool
    {
        return $user->hasPermissionTo('roles.assign');
    }
}
