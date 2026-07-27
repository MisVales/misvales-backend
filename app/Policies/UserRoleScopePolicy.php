<?php

namespace App\Policies;

use App\Models\UserRoleScope;
use App\Models\User;

class UserRoleScopePolicy
{
    /**
     * El Gerente General tiene acceso absoluto.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role && $user->role->code === 'GENERAL_MANAGER') {
            return true;
        }

        return null;
    }

    /**
     * Determina si el usuario puede ver los alcances de rol.
     */
    public function viewAny(User $user): bool
    {
        $role = $user->role->code ?? '';

        return in_array($role, ['ADMINISTRATOR', 'BRANCH_MANAGER']);
    }

    /**
     * Determina si el usuario puede crear un alcance de rol.
     */
    public function create(User $user): bool
    {
        return false; 
    }
}