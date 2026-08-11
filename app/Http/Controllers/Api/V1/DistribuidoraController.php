<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\EnlistarDistribuidorasRequest;
use App\Http\Resources\Api\V1\Distribuidora\DistribuidoraDetalleResource;
use App\Http\Resources\Api\V1\Distribuidora\DistribuidoraResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ServicioConsultaDistribuidora;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DistribuidoraController extends Controller
{
    public function index(
        EnlistarDistribuidorasRequest $request,
        ServicioConsultaDistribuidora $servicio,
    ): AnonymousResourceCollection {
        Gate::authorize('viewAny', Distribuidora::class);

        return DistribuidoraResource::collection($servicio->listar($request->validated(), $request->user()));
    }

    public function show(Distribuidora $distributor): DistribuidoraDetalleResource
    {
        Gate::authorize('view', $distributor);
        $distributor->load([
            'usuario', 'sucursal', 'solicitud.autorizacion', 'solicitud.datosPersonales', 'coordinadorVigente.coordinator',
            'categoriaVigente.versionCategoria', 'asignacionesCategoria.versionCategoria',
            'lineaCredito.movimientos', 'lineaCredito.restricciones',
        ]);

        return new DistribuidoraDetalleResource($distributor);
    }
}
