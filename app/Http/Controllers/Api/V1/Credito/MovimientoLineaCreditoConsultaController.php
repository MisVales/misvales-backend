<?php

namespace App\Http\Controllers\Api\V1\Credito;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Credito\MovimientosFiltroRequest;
use App\Http\Resources\Api\V1\Credito\MovimientoLineaCreditoResource;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Services\Credito\AuditorIncrementos;
use Illuminate\Support\Facades\Gate;

class MovimientoLineaCreditoConsultaController extends Controller
{
    public function index(MovimientosFiltroRequest $request, string $distributorId)
    {
        $user = $request->user();

        $linea = LineaCredito::query()->with('distribuidora')->where('distributor_id', $distributorId)->firstOrFail();

        Gate::authorize('viewMovements', $linea);

        app(AuditorIncrementos::class)->registrar(
            'EV-READ-MOVEMENTS',
            'credit_lines',
            $linea->id,
            null,
            $user,
            $linea->distribuidora?->branch_id,
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
            $queryMovimientos->where('occurred_at', '>=', $filtros['occurred_from'].' 00:00:00');
        }

        if (isset($filtros['occurred_to'])) {
            $queryMovimientos->where('occurred_at', '<=', $filtros['occurred_to'].' 23:59:59');
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
