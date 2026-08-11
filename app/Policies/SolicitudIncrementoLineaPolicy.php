<?php

namespace App\Policies;

use App\Enums\EstadoSolicitudIncremento;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\UserRoleScope;

class SolicitudIncrementoLineaPolicy
{
    public function before(User $user)
    {
        if ($user->state !== 'ACTIVE') {
            return false;
        }
    }

    public function viewAny(User $user): bool
    {
        return current(array_filter([
            $user->hasPermissionTo('credit_increase_requests.view_global'),
            $user->hasPermissionTo('credit_increase_requests.view_branch'),
            $user->hasPermissionTo('credit_increase_requests.view_assigned'),
            $user->hasPermissionTo('credit_increase_requests.view_own'),
        ])) !== false;
    }

    public function view(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($user->hasPermissionTo('credit_increase_requests.view_global')) {
            return true;
        }

        if ($user->hasPermissionTo('credit_increase_requests.view_branch')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope_type', 'BRANCH')->first();
            if ($managerScope && $managerScope->branch_id === $solicitud->branch_id) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_increase_requests.view_assigned')) {
            if (CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $solicitud->distributor_id)->where('status', 'ACTIVE')->exists()) {
                return true;
            }
        }

        if ($user->hasPermissionTo('credit_increase_requests.view_own')) {
            $distribuidora = \App\Models\Distribuidora::where('user_id', $user->id)->first();
            if ($distribuidora && $distribuidora->id === $solicitud->distributor_id) {
                return true;
            }
            if ($user->id === $solicitud->distributor_id) {
                return true;
            }
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('credit_increase_requests.create_own');
    }

    public function preauthorize(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($solicitud->status !== EstadoSolicitudIncremento::REQUESTED) return false;
        
        if ($user->hasPermissionTo('credit_increase_requests.preauthorize_assigned')) {
            return CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $solicitud->distributor_id)->where('status', 'ACTIVE')->exists();
        }

        return false;
    }

    public function rejectByCoordinator(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($solicitud->status !== EstadoSolicitudIncremento::REQUESTED) return false;

        if ($user->hasPermissionTo('credit_increase_requests.reject_assigned')) {
            return CoordinatorDistributorAssignment::where('coordinator_id', $user->id)->where('distributor_id', $solicitud->distributor_id)->where('status', 'ACTIVE')->exists();
        }

        return false;
    }

    public function managerDecision(User $user, SolicitudIncrementoLinea $solicitud): bool
    {
        if ($solicitud->status !== EstadoSolicitudIncremento::PREAUTHORIZED) return false;
        
        // Separación de funciones (propietario)
        if ($solicitud->requested_by === $user->id || $solicitud->distributor_id === $user->id) {
            return false;
        }

        if ($user->hasPermissionTo('credit_increase_requests.decide_global')) {
            return true;
        }

        if ($user->hasPermissionTo('credit_increase_requests.decide_branch')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)->where('status', 'ACTIVE')->where('scope_type', 'BRANCH')->first();
            if ($managerScope && $managerScope->branch_id === $solicitud->branch_id) {
                return true;
            }
        }

        return false;
    }
}
