<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;

class UserRoleScopePolicy
{
    /**
     * El Gerente General tiene acceso absoluto.
     */
    public function before(User $user, string $ability): ?bool
    {
        $role = $this->getUserRoleCode($user);

        if ($role === 'GENERAL_MANAGER') {
            return true;
        }

        return null;
    }

    /**
     * Determina si el usuario puede ver los alcances de rol.
     */
    public function viewAny(User $user): bool
    {
        $role = $this->getUserRoleCode($user);

        return in_array($role, ['ADMINISTRATOR', 'GENERAL_MANAGER', 'BRANCH_MANAGER']);
    }

    /**
     * Determina si el usuario puede crear un alcance de rol.
     */
    public function create(User $user): bool
    {
        $role = $this->getUserRoleCode($user);

        return $role === 'GENERAL_MANAGER';
    }

    /**
     * Método auxiliar robusto para obtener el código del rol como cadena de texto.
     */
    private function getUserRoleCode(User $user): string
    {
        $roleCode = null;

        if ($user->relationLoaded('role') && $user->role) {
            $roleCode = $user->role->code;
        } elseif ($user->role_id) {
            $role = Role::find($user->role_id);
            $roleCode = $role ? $role->code : null;
        }

        if (! $roleCode) {
            return '';
        }

        if (is_object($roleCode)) {
            if (property_exists($roleCode, 'value')) {
                return (string) $roleCode->value;
            }
            if (method_exists($roleCode, 'value')) {
                return (string) $roleCode->value();
            }

            return (string) $roleCode;
        }

        return (string) $roleCode;
    }
}
