<?php

namespace App\Policies;

use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\User;
use App\Models\UserRoleScope;

class LineaCreditoPolicy
{
    public function before(User $user)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function view(User $user, LineaCredito $lineaCredito): bool
    {
        if ($user->hasPermissionTo('credit_lines.view_global')) {
            return true;
        }

        if ($user->hasPermissionTo('credit_lines.view_branch')) {
            if ($this->hasBranchAccess($user, $lineaCredito)) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_lines.view_assigned')) {
            if (CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $lineaCredito->distributor_id)->where('status', 'ACTIVE')->exists()) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_lines.view_own')) {
            $distribuidora = Distribuidora::where('user_id', $user->id)->first();
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
            if ($this->hasBranchAccess($user, $lineaCredito)) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_line_movements.view_assigned')) {
            if (CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $lineaCredito->distributor_id)->where('status', 'ACTIVE')->exists()) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_line_movements.view_own')) {
            $distribuidora = Distribuidora::where('user_id', $user->id)->first();
            if ($distribuidora && $distribuidora->id === $lineaCredito->distributor_id) {
                return true;
            }
            if ($user->id === $lineaCredito->distributor_id) {
                return true;
            }
        }

        return false;
    }

    private function hasBranchAccess(User $user, LineaCredito $lineaCredito): bool
    {
        $branchId = $lineaCredito->distribuidora?->branch_id;

        if ($branchId === null) {
            $branchId = Distribuidora::query()->whereKey($lineaCredito->distributor_id)->value('branch_id');
        }

        return $branchId !== null && UserRoleScope::query()
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'BRANCH')
            ->where('branch_id', $branchId)
            ->exists();
    }
}
