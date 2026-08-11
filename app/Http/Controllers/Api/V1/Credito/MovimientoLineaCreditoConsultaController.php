<?php

namespace App\Http\Controllers\Api\V1\Credito;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credito\MovimientosFiltroRequest;
use App\Http\Resources\Api\V1\Credito\MovimientoLineaCreditoResource;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\UserRoleScope;

class MovimientoLineaCreditoConsultaController extends Controller
{
    public function index(MovimientosFiltroRequest $request, string $distributorId)
    {
        $user = $request->user();

        // Aplicar la misma seguridad 404 que en la consulta de línea
        $queryLinea = LineaCredito::where('distributor_id', $distributorId);

        if ($user->hasPermissionTo('credit_line_movements.view_own')) {
            $distribuidora = \App\Models\Distribuidora::where('user_id', $user->id)->first();
            if ($distribuidora) {
                $queryLinea->where('distributor_id', $distribuidora->id);
            } else {
                $queryLinea->where('distributor_id', $user->id);
            }
        } elseif ($user->hasPermissionTo('credit_line_movements.view_assigned')) {
            $hasAssignment = CoordinatorDistributorAssignment::where('coordinator_id', $user->id)
                ->where('distributor_id', $distributorId)
                ->where('status', 'ACTIVE')
                ->exists();
                
            if (!$hasAssignment) {
                $queryLinea->where('id', 'invalid-uuid'); 
            }
        } elseif ($user->hasPermissionTo('credit_line_movements.view_branch')) {
            $managerScope = UserRoleScope::where('user_id', $user->id)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            $distributorScope = UserRoleScope::where('user_id', $distributorId)
                ->where('status', 'ACTIVE')
                ->where('scope_type', 'BRANCH')
                ->first();

            if (!$managerScope || !$distributorScope || $managerScope->branch_id !== $distributorScope->branch_id) {
                $queryLinea->where('id', 'invalid-uuid');
            }
        } elseif (!$user->hasPermissionTo('credit_line_movements.view_global')) {
            $queryLinea->where('id', 'invalid-uuid');
        }

        $linea = $queryLinea->firstOrFail();
        
        \Illuminate\Support\Facades\Gate::authorize('viewMovements', $linea);

        app(\App\Services\Credito\AuditorIncrementos::class)->registrar(
            'EV-READ-MOVEMENTS',
            'credit_lines',
            $linea->id,
            null,
            $user,
            $linea->branch_id ?? null,
            [],
            [],
            'Consulta de movimientos de línea de crédito.',
            'SUCCESS'
        );

        // Construir la consulta de movimientos
        $queryMovimientos = MovimientoLineaCredito::with('realizadoPor')
            ->where('credit_line_id', $linea->id);

        $filtros = $request->validated();

        if (isset($filtros['type'])) {
            $queryMovimientos->where('type', $filtros['type']);
        }

        if (isset($filtros['occurred_from'])) {
            $queryMovimientos->where('occurred_at', '>=', $filtros['occurred_from'] . ' 00:00:00');
        }

        if (isset($filtros['occurred_to'])) {
            $queryMovimientos->where('occurred_at', '<=', $filtros['occurred_to'] . ' 23:59:59');
        }

        if (isset($filtros['sort'])) {
            $sort = $filtros['sort'];
            $desc = str_starts_with($sort, '-');
            $column = ltrim($sort, '-');
            $queryMovimientos->orderBy($column, $desc ? 'desc' : 'asc');
        } else {
            $queryMovimientos->orderBy('sequence', 'desc'); // Default
        }

        $perPage = $filtros['per_page'] ?? 15;
        
        $paginator = $queryMovimientos->paginate($perPage);

        return MovimientoLineaCreditoResource::collection($paginator);
    }
}
