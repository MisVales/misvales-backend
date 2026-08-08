<?php

namespace App\Services\Cliente;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class ServicioConsultaCliente
{
    private const SQL_SALDO = "COALESCE((SELECT SUM(CASE WHEN entry_type IN ('DEBT', 'ADJUSTMENT_INCREASE') THEN amount WHEN entry_type IN ('PAYMENT', 'PARTIAL_PAYMENT', 'ADJUSTMENT_DECREASE') THEN -amount ELSE 0 END) FROM client_portfolio_entries WHERE client_id = clients.id), 0)";

    private const SQL_REDUCCIONES = "COALESCE((SELECT SUM(amount) FROM client_portfolio_entries WHERE client_id = clients.id AND entry_type IN ('PAYMENT', 'PARTIAL_PAYMENT', 'ADJUSTMENT_DECREASE')), 0)";

    public function listar(array $filtros, User $actor): LengthAwarePaginator
    {
        $consulta = Cliente::query()
            ->with(['asignacionVigente.distribuidora.usuario', 'asignacionVigente.sucursal'])
            ->withCount('movimientosCartera')
            ->withSum(['movimientosCartera as portfolio_increases_sum_amount' => fn (Builder $q) => $q->whereIn('entry_type', ['DEBT', 'ADJUSTMENT_INCREASE'])], 'amount')
            ->withSum(['movimientosCartera as portfolio_reductions_sum_amount' => fn (Builder $q) => $q->whereIn('entry_type', ['PAYMENT', 'PARTIAL_PAYMENT', 'ADJUSTMENT_DECREASE'])], 'amount')
            ->withMax('movimientosCartera', 'last_payment_at');

        $this->restringirAlcance($consulta, $actor);
        $this->aplicarFiltros($consulta, $filtros);

        return $consulta
            ->orderBy($filtros['sort'] ?? 'created_at', $filtros['direction'] ?? 'desc')
            ->paginate($filtros['per_page'] ?? 20)
            ->withQueryString();
    }

    public function cargarDetalle(Cliente $cliente, User $actor): Cliente
    {
        $relaciones = [
            'domicilioVigente', 'cuentaBancariaVigente',
            'asignacionVigente.distribuidora.usuario', 'asignacionVigente.sucursal',
            'movimientosCartera',
        ];

        if ($actor->hasPermissionTo('clients.view_assignment_history')) {
            $relaciones[] = 'asignacionesDistribuidora.distribuidora';
            $relaciones[] = 'asignacionesDistribuidora.sucursal';
            $relaciones[] = 'domicilios';
        }
        if ($actor->hasPermissionTo('clients.view_bank_accounts')) {
            $relaciones[] = 'cuentasBancarias';
        }

        $cliente->load($relaciones);
        $cliente->loadCount('movimientosCartera');
        $cliente->loadSum(['movimientosCartera as portfolio_increases_sum_amount' => fn (Builder $q) => $q->whereIn('entry_type', ['DEBT', 'ADJUSTMENT_INCREASE'])], 'amount');
        $cliente->loadSum(['movimientosCartera as portfolio_reductions_sum_amount' => fn (Builder $q) => $q->whereIn('entry_type', ['PAYMENT', 'PARTIAL_PAYMENT', 'ADJUSTMENT_DECREASE'])], 'amount');
        $cliente->loadMax('movimientosCartera', 'last_payment_at');

        return $cliente;
    }

    private function restringirAlcance(Builder $consulta, User $actor): void
    {
        $alcances = $actor->roleScopes()->with('role')->where('status', 'ACTIVE')->whereNull('revoked_at')->get();

        if ($alcances->contains(fn ($alcance): bool => $alcance->scope_type === 'GLOBAL'
            && in_array($alcance->role->code, ['general_manager', 'admin'], true))) {
            return;
        }

        $sucursales = $alcances
            ->filter(fn ($alcance): bool => $alcance->scope_type === 'BRANCH' && $alcance->role->code === 'branch_manager')
            ->pluck('branch_id')->filter()->unique();
        $distribuidoras = $alcances->where('scope_type', 'DISTRIBUTOR')->pluck('scope_id')->filter()->unique();

        if ($alcances->contains(fn ($alcance): bool => $alcance->role->code === 'coordinator')) {
            $asignadas = $actor->getConnection()->table('coordinator_distributor_assignments')
                ->where('coordinator_id', $actor->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->pluck('distributor_id');
            $distribuidoras = $distribuidoras->merge($asignadas)->unique();
        }

        $consulta->whereHas('asignacionVigente', function (Builder $asignacion) use ($sucursales, $distribuidoras): void {
            $asignacion->where(function (Builder $alcance) use ($sucursales, $distribuidoras): void {
                if ($sucursales->isNotEmpty()) {
                    $alcance->whereIn('branch_id', $sucursales);
                }
                if ($distribuidoras->isNotEmpty()) {
                    $metodo = $sucursales->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                    $alcance->{$metodo}('distributor_id', $distribuidoras);
                }
                if ($sucursales->isEmpty() && $distribuidoras->isEmpty()) {
                    $alcance->whereRaw('1 = 0');
                }
            });
        });
    }

    private function aplicarFiltros(Builder $consulta, array $filtros): void
    {
        $consulta
            ->when($filtros['search'] ?? null, function (Builder $query, string $search): void {
                $search = trim($search);
                $query->where(function (Builder $subconsulta) use ($search): void {
                    $subconsulta->where('client_number', 'ilike', "%{$search}%")
                        ->orWhere('first_name', 'ilike', "%{$search}%")
                        ->orWhere('first_last_name', 'ilike', "%{$search}%")
                        ->orWhere('second_last_name', 'ilike', "%{$search}%");
                });
            })
            ->when($filtros['branch_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas('asignacionVigente', fn (Builder $a) => $a->where('branch_id', $id)))
            ->when($filtros['distributor_id'] ?? null, fn (Builder $query, string $id) => $query->whereHas('asignacionVigente', fn (Builder $a) => $a->where('distributor_id', $id)))
            ->when($filtros['created_from'] ?? null, fn (Builder $query, string $fecha) => $query->whereDate('created_at', '>=', $fecha))
            ->when($filtros['created_to'] ?? null, fn (Builder $query, string $fecha) => $query->whereDate('created_at', '<=', $fecha));

        if (array_key_exists('has_portfolio_balance', $filtros)) {
            $operador = filter_var($filtros['has_portfolio_balance'], FILTER_VALIDATE_BOOL) ? '>' : '<=';
            $consulta->whereRaw(self::SQL_SALDO." {$operador} 0");
        }

        match ($filtros['portfolio_status'] ?? null) {
            'PENDING' => $consulta->whereRaw(self::SQL_SALDO.' > 0')->whereRaw(self::SQL_REDUCCIONES.' = 0'),
            'PARTIALLY_PAID' => $consulta->whereRaw(self::SQL_SALDO.' > 0')->whereRaw(self::SQL_REDUCCIONES.' > 0'),
            'PAID' => $consulta->whereRaw(self::SQL_SALDO.' <= 0')->whereHas('movimientosCartera'),
            default => null,
        };
    }
}
