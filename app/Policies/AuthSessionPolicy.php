<?php

namespace App\Policies;

use App\Models\AuthSession;
use App\Models\User;

class AuthSessionPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    /**
     * Prevención IDOR (Punto 40): Solo puede revocar una sesión si le pertenece a él mismo.
     */
    public function delete(User $user, AuthSession $session): bool
    {
        return $user->id === $session->user_id;
    }
}
