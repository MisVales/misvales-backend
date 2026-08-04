<?php

namespace App\Policies;

use App\Models\User;

final class BranchPolicy
{
    public function before(User $user): ?bool
    {
        return $user->state === 'ACTIVE' ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('branches.create');
    }

    public function updateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.update');
    }

    public function activateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.activate');
    }

    public function deactivateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.deactivate');
    }
}
