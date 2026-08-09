<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RolePolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('roles.view');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('roles.view');
    }

    public function updatePermissions(User $user, Role $role): Response
    {
        if (! $user->hasPermissionTo('roles.manage_permissions')) {
            return Response::deny('No tiene permiso para administrar roles.');
        }

        if ($role->is_system) {
            return Response::deny('Los roles del sistema son inmutables y no pueden modificarse a través de la API.');
        }

        return Response::allow();
    }
}
