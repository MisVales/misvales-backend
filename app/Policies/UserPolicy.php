<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Intercepta todas las llamadas (Defensa en Profundidad).
     * Denegación por defecto si el usuario no está activo (Punto 39).
     */
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.update');
    }

    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('users.manage_state');
    }
}
