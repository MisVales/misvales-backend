<?php

namespace App\Policies;

use App\Models\User;

class SecurityEventPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAudit(User $user): bool
    {
        return $user->hasPermissionTo('audit.view');
    }
}
