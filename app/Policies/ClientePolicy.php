<?php

namespace App\Policies;

use App\Models\Cliente;
use App\Models\User;

class ClientePolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->hasPermissionTo('clients.view');
    }

    public function view(User $usuario, Cliente $cliente): bool
    {
        return $usuario->hasPermissionTo('clients.view') && $this->estaEnAlcance($usuario, $cliente);
    }

    public function create(User $usuario): bool
    {
        return $usuario->hasPermissionTo('clients.create')
            && $usuario->distribuidora()->where('status', 'ACTIVE')->exists()
            && $usuario->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where('scope_type', 'DISTRIBUTOR')
                ->whereExists(function ($consulta) use ($usuario): void {
                    $consulta->selectRaw('1')
                        ->from('distributors')
                        ->whereColumn('distributors.id', 'user_role_scopes.scope_id')
                        ->where('distributors.user_id', $usuario->id);
                })->exists();
    }

    public function viewSensitive(User $usuario, Cliente $cliente): bool
    {
        return $usuario->hasPermissionTo('clients.view_sensitive') && $this->estaEnAlcance($usuario, $cliente);
    }

    public function viewBankAccounts(User $usuario, Cliente $cliente): bool
    {
        return $usuario->hasPermissionTo('clients.view_bank_accounts') && $this->estaEnAlcance($usuario, $cliente);
    }

    public function manageBankAccounts(User $usuario, Cliente $cliente): bool
    {
        return $usuario->hasPermissionTo('clients.manage_bank_accounts') && $this->esDistribuidoraResponsable($usuario, $cliente);
    }

    public function viewPortfolio(User $usuario, Cliente $cliente): bool
    {
        return $usuario->hasPermissionTo('clients.view_portfolio') && $this->estaEnAlcance($usuario, $cliente);
    }

    public function managePortfolio(User $usuario, Cliente $cliente): bool
    {
        return $usuario->hasPermissionTo('clients.manage_portfolio') && $this->esDistribuidoraResponsable($usuario, $cliente);
    }

    private function estaEnAlcance(User $usuario, Cliente $cliente): bool
    {
        $asignacion = $cliente->asignacionVigente()->first();
        if ($asignacion === null) {
            return false;
        }

        $alcances = $usuario->roleScopes()
            ->with('role')
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->get();

        if ($alcances->contains(fn ($alcance): bool => $alcance->scope_type === 'GLOBAL'
            && in_array($alcance->role->code, ['general_manager', 'admin'], true))) {
            return true;
        }

        if ($alcances->contains(fn ($alcance): bool => $alcance->scope_type === 'BRANCH'
            && $alcance->role->code === 'branch_manager'
            && $alcance->branch_id === $asignacion->branch_id)) {
            return true;
        }

        if ($alcances->contains(fn ($alcance): bool => $alcance->scope_type === 'DISTRIBUTOR'
            && $alcance->scope_id === $asignacion->distributor_id)) {
            return true;
        }

        $esCoordinadorDeSucursal = $alcances->contains(fn ($alcance): bool => $alcance->role->code === 'coordinator'
            && $alcance->branch_id === $asignacion->branch_id);

        return $esCoordinadorDeSucursal && $usuario->getConnection()->table('coordinator_distributor_assignments')
            ->where('coordinator_id', $usuario->id)
            ->where('distributor_id', $asignacion->distributor_id)
            ->where('branch_id', $asignacion->branch_id)
            ->where('status', 'ACTIVE')
            ->whereNull('valid_to')
            ->exists();
    }

    private function esDistribuidoraResponsable(User $usuario, Cliente $cliente): bool
    {
        return $cliente->asignacionVigente()
            ->whereHas('distribuidora', fn ($consulta) => $consulta->where('user_id', $usuario->id)->where('status', 'ACTIVE'))
            ->exists();
    }
}
