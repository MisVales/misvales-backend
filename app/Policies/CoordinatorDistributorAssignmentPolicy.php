<?php

namespace App\Policies;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\User;

class CoordinatorDistributorAssignmentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role && $user->role->code === 'GENERAL_MANAGER') {
            return true;
        }

        return null; 
    }

    public function view(User $user, CoordinatorDistributorAssignment $assignment): bool
    {
        $role = $user->role->code ?? '';

        if ($role === 'ADMINISTRATOR') {
            return true;
        }

        if ($role === 'BRANCH_MANAGER') {
            return $user->branch_id === $assignment->branch_id;
        }

        if ($role === 'COORDINATOR') {
            return $user->id === $assignment->coordinator_user_id;
        }

        return false;
    }


    public function delete(User $user, CoordinatorDistributorAssignment $assignment): bool
    {
        $role = $user->role->code ?? '';

        if ($role === 'ADMINISTRATOR') {
            return false;
        }
     
        if ($role === 'BRANCH_MANAGER') {
            return $user->branch_id === $assignment->branch_id;
        }

        return false;
    }


    public function create(User $user, Branch $branch): bool
    {
        $role = $user->role->code ?? '';
        if ($role === 'ADMINISTRATOR') {
            return false;
        }
        if ($role === 'BRANCH_MANAGER') {
            return $user->branch_id === $branch->id;
        }
        return false; 
    }
}