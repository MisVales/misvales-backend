<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.view');
    }

    public function view(User $user, Branch $branch): bool
    {
        if (! $user->hasPermissionTo('branches.view')) {
            return false;
        }

        return $user->hasScopeForBranch($branch->id);
    }

    public function create(User $user): bool
    {
        // General Manager with global scope and branches.create permission
        if (! $user->hasPermissionTo('branches.create')) {
            return false;
        }

        // Only users with GLOBAL scope can create branches
        return $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'GLOBAL')
            ->exists();
    }

    public function update(User $user, Branch $branch): bool
    {
        if (! $user->hasPermissionTo('branches.update')) {
            return false;
        }

        return $user->hasScopeForBranch($branch->id);
    }

    public function updateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.update');
    }

    public function activateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.manage_state');
    }

    public function deactivateAny(User $user): bool
    {
        return $user->hasPermissionTo('branches.manage_state');
    }

    public function manageState(User $user, Branch $branch): bool
    {
        if (! $user->hasPermissionTo('branches.manage_state')) {
            return false;
        }

        return $user->hasScopeForBranch($branch->id);
    }

    public function managePersonnel(User $user, Branch $branch): bool
    {
        if (! $user->hasPermissionTo('roles.assign')) {
            return false;
        }

        return $user->hasScopeForBranch($branch->id);
    }
}
