<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Credito\MovimientoLineaCreditoResource;
use App\Models\LineaCredito;
use App\Services\Credito\ServicioConsultaMovimientosCredito;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MovimientoLineaCreditoController extends Controller
{
    protected ServicioConsultaMovimientosCredito $consultaMovimientos;

    public function __construct(ServicioConsultaMovimientosCredito $consultaMovimientos)
    {
        $this->consultaMovimientos = $consultaMovimientos;
    }

    public function index(Request $request, LineaCredito $linea)
    {
        Gate::authorize('view', $linea);

        $perPage = $request->query('per_page', 15);
        $movimientos = $this->consultaMovimientos->consultar($linea, $perPage);

        return MovimientoLineaCreditoResource::collection($movimientos);
    }
}
