<?php

namespace App\Policies;

use App\Models\SolicitudDistribuidora;
use App\Models\User;
use App\Models\UserRoleScope;

final class SolicitudDistribuidoraPolicy
{
    public function before(User $user): ?bool
    {
        return $user->state === 'ACTIVE' ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('distributor_applications.view');
    }

    public function create(User $user): bool
    {
        // CAJERA -> crear solicitud DENEGADO
        if ($user->hasRole('cashier')) return false;

        return $user->hasPermissionTo('distributor_applications.create');
    }

    public function view(User $user, SolicitudDistribuidora $solicitud): bool
    {
        return $this->viewAny($user) && $this->estaEnAlcance($user, $solicitud);
    }

    public function update(User $user, SolicitudDistribuidora $solicitud): bool
    {
        // VERIFICADOR -> corregir expediente DENEGADO
        // ADMINISTRADOR -> modificar solicitud DENEGADO
        if ($user->hasRole('verifier') || $user->hasRole('admin')) return false;

        if (! $user->hasPermissionTo('distributor_applications.update')) {
            return false;
        }

        return $this->puedeOperar($user, $solicitud);
    }

    public function submit(User $user, SolicitudDistribuidora $solicitud): bool
    {
        // COORDINADOR -> autorización final DENEGADO
        if ($user->hasRole('coordinator')) return false;

        return $user->hasPermissionTo('distributor_applications.submit')
            && $this->puedeOperar($user, $solicitud);
    }

    private function estaEnAlcance(User $user, SolicitudDistribuidora $solicitud): bool
    {
        $asignaciones = $this->asignacionesActivas($user);

        if ($asignaciones->contains(fn ($asignacion): bool => in_array($asignacion->role_code, ['general_manager', 'admin'], true)
            && $asignacion->scope_type === 'GLOBAL')) {
            return true;
        }

        return $asignaciones->contains(fn ($asignacion): bool => $asignacion->branch_id === $solicitud->branch_id
            && in_array($asignacion->role_code, ['branch_manager', 'coordinator'], true));
    }

    private function puedeOperar(User $user, SolicitudDistribuidora $solicitud): bool
    {
        $asignaciones = $this->asignacionesActivas($user);

        if ($asignaciones->contains(fn ($asignacion): bool => $asignacion->role_code === 'general_manager'
            && $asignacion->scope_type === 'GLOBAL')) {
            return true;
        }

        if ($asignaciones->contains(fn ($asignacion): bool => $asignacion->role_code === 'branch_manager'
            && $asignacion->branch_id === $solicitud->branch_id)) {
            return true;
        }

        return $solicitud->coordinator_id === $user->id
            && $asignaciones->contains(fn ($asignacion): bool => $asignacion->role_code === 'coordinator'
                && $asignacion->branch_id === $solicitud->branch_id);
    }

    private function asignacionesActivas(User $user)
    {
        return UserRoleScope::query()
            ->select(['roles.code as role_code', 'user_role_scopes.branch_id', 'user_role_scopes.scope_type'])
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('user_role_scopes.user_id', $user->id)
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->get();
    }
}
