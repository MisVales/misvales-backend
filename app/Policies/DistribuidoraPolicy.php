<?php

namespace App\Policies;

use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\User;

class DistribuidoraPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->hasPermissionTo('distributors.view_any');
    }

    public function view(User $usuario, Distribuidora $distribuidora): bool
    {
        if (! $usuario->hasPermissionTo('distributors.view')) {
            return false;
        }

        if ($distribuidora->user_id === $usuario->id) {
            return true;
        }

        $alcances = $this->alcances($usuario);
        if ($alcances->contains(fn ($alcance) => in_array($alcance->role->code, ['general_manager', 'admin'], true) && $alcance->scope_type === 'GLOBAL')) {
            return true;
        }

        if (! $alcances->contains('branch_id', $distribuidora->branch_id)) {
            return false;
        }

        if ($alcances->contains(fn ($alcance) => $alcance->role->code === 'coordinator')) {
            return $distribuidora->asignacionesCoordinador()
                ->where('coordinator_id', $usuario->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->exists();
        }

        return true;
    }

    public function activate(User $usuario, DistributorApplication $solicitud): bool
    {
        if (! $usuario->hasPermissionTo('distributors.activate')) {
            return false;
        }

        return $usuario->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where(function ($consulta) use ($solicitud): void {
                $consulta->where('scope_type', 'GLOBAL')
                    ->orWhere('branch_id', $solicitud->branch_id);
            })
            ->whereHas('role', fn ($consulta) => $consulta->whereIn('code', ['general_manager', 'branch_manager']))
            ->exists();
    }

    public function assignCategory(User $usuario, Distribuidora $distribuidora): bool
    {
        return $usuario->hasPermissionTo('distributors.assign_category')
            && $this->esGerenteConAlcance($usuario, $distribuidora->branch_id);
    }

    public function viewCategoryHistory(User $usuario, Distribuidora $distribuidora): bool
    {
        return $usuario->hasPermissionTo('distributors.view_category_history') && $this->view($usuario, $distribuidora);
    }

    public function resendActivation(User $usuario, Distribuidora $distribuidora): bool
    {
        return $usuario->hasPermissionTo('distributors.resend_activation')
            && $this->esGerenteConAlcance($usuario, $distribuidora->branch_id);
    }

    public function assignCoordinator(User $usuario, Distribuidora $distribuidora): bool
    {
        return $usuario->hasPermissionTo('distributors.assign_coordinator')
            && $this->esGerenteConAlcance($usuario, $distribuidora->branch_id);
    }

    public function viewCoordinatorHistory(User $usuario, Distribuidora $distribuidora): bool
    {
        return $usuario->hasPermissionTo('distributors.view_assignment_history') && $this->view($usuario, $distribuidora);
    }

    public function changeStatus(User $usuario, Distribuidora $distribuidora): bool
    {
        return $usuario->hasPermissionTo('distributors.change_status')
            && $this->esGerenteConAlcance($usuario, $distribuidora->branch_id);
    }

    private function esGerenteConAlcance(User $usuario, string $sucursalId): bool
    {
        return $this->alcances($usuario)->contains(fn ($alcance) => in_array($alcance->role->code, ['general_manager', 'branch_manager'], true)
            && ($alcance->scope_type === 'GLOBAL' || $alcance->branch_id === $sucursalId));
    }

    private function alcances(User $usuario)
    {
        return $usuario->roleScopes()->with('role')->where('status', 'ACTIVE')->whereNull('revoked_at')->get();
    }
}
