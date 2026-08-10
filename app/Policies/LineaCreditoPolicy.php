<?php

namespace App\Policies;

use App\Models\LineaCredito;
use App\Models\User;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\UserRoleScope;

class LineaCreditoPolicy
{
    public function before(User $user)
    {
        if ($user->status !== 'ACTIVE') {
            return false;
        }
    }

    public function view(User $user, LineaCredito $lineaCredito): bool
    {
        if ($user->hasPermissionTo('credit_lines.view_global')) {
            return true;
        }

        if ($user->hasPermissionTo('credit_lines.view_branch')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope_type', 'BRANCH')->first();
            $distributorScope = UserRoleScope::where('user_id', $lineaCredito->distributor_id)->where('status', 'ACTIVE')->where('scope_type', 'BRANCH')->first();
            if ($managerScope && $distributorScope && $managerScope->branch_id === $distributorScope->branch_id) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_lines.view_assigned')) {
            if (CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $lineaCredito->distributor_id)->where('status', 'ACTIVE')->exists()) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_lines.view_own')) {
            $distribuidora = \App\Models\Distribuidora::where('user_id', $user->id)->first();
            if ($distribuidora && $distribuidora->id === $lineaCredito->distributor_id) {
                return true;
            }
            if ($user->id === $lineaCredito->distributor_id) {
                return true;
            }
        }

        return false;
    }

    public function viewMovements(User $user, LineaCredito $lineaCredito): bool
    {
        if ($user->hasPermissionTo('credit_line_movements.view_global')) {
            return true;
        }

        if ($user->hasPermissionTo('credit_line_movements.view_branch')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope_type', 'BRANCH')->first();
            $distributorScope = UserRoleScope::where('user_id', $lineaCredito->distributor_id)->where('status', 'ACTIVE')->where('scope_type', 'BRANCH')->first();
            if ($managerScope && $distributorScope && $managerScope->branch_id === $distributorScope->branch_id) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_line_movements.view_assigned')) {
            if (CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $lineaCredito->distributor_id)->where('status', 'ACTIVE')->exists()) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_line_movements.view_own')) {
            $distribuidora = \App\Models\Distribuidora::where('user_id', $user->id)->first();
            if ($distribuidora && $distribuidora->id === $lineaCredito->distributor_id) {
                return true;
            }
            if ($user->id === $lineaCredito->distributor_id) {
                return true;
            }
        }

        return false;
    }
}
