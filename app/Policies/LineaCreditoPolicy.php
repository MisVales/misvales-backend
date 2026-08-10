<?php

namespace App\Policies;

use App\Models\LineaCredito;
use App\Models\User;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\UserRoleScope;

class LineaCreditoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('general_manager') || $user->hasRole('branch_manager') || $user->hasRole('coordinator');
    }

    public function view(User $user, LineaCredito $lineaCredito): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('general_manager')) {
            return true;
        }

        if ($user->hasRole('distributor')) {
            return $user->id === $lineaCredito->distributor_id;
        }

        if ($user->hasRole('coordinator')) {
            return CoordinatorDistributorAssignment::where('coordinator_id', $user->id)
                ->where('distributor_id', $lineaCredito->distributor_id)
                ->where('status', 'ACTIVE')
                ->exists();
        }

        if ($user->hasRole('branch_manager')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            $distributorScope = UserRoleScope::where('user_id', $lineaCredito->distributor_id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            if ($managerScope && $distributorScope) {
                return $managerScope->branch_id === $distributorScope->branch_id;
            }
        }

        return false;
    }
}
