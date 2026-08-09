<?php

namespace App\Policies;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;

class CoordinatorAssignmentPolicy
{
    public function before(User $user, string $ability)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('assignments.manage') || $user->hasPermissionTo('branches.view');
    }

    public function manage(User $user, string $branchId): bool
    {
        if (!$user->hasPermissionTo('assignments.manage')) {
            return false;
        }

        return $user->hasScopeForBranch($branchId);
    }
}
