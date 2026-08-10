<?php

namespace App\Policies;

use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\UserRoleScope;

class SolicitudIncrementoLineaPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('general_manager')) {
            return true;
        }

        if ($user->hasRole('distributor')) {
            return $user->id === $solicitud->distributor_id;
        }

        if ($user->hasRole('coordinator')) {
            return CoordinatorDistributorAssignment::where('coordinator_id', $user->id)
                ->where('distributor_id', $solicitud->distributor_id)
                ->where('status', 'ACTIVE')
                ->exists();
        }

        if ($user->hasRole('branch_manager')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            $distributorScope = UserRoleScope::where('user_id', $solicitud->distributor_id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            if ($managerScope && $distributorScope) {
                return $managerScope->branch_id === $distributorScope->branch_id;
            }
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('distributor');
    }

    public function preauthorize(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->hasRole('coordinator')) {
            return CoordinatorDistributorAssignment::where('coordinator_id', $user->id)
                ->where('distributor_id', $solicitud->distributor_id)
                ->where('status', 'ACTIVE')
                ->exists();
        }

        return false;
    }

    public function decide(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($user->hasRole('admin') || $user->hasRole('general_manager')) {
            return true;
        }

        if ($user->hasRole('branch_manager')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            $distributorScope = UserRoleScope::where('user_id', $solicitud->distributor_id)
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
