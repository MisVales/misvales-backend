<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\CambiarEstadoDistribuidoraRequest;
use App\Http\Resources\Api\V1\Distribuidora\DistribuidoraDetalleResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ServicioEstadoDistribuidora;
use Illuminate\Support\Facades\Gate;

class EstadoDistribuidoraController extends Controller
{
    public function deshabilitar(CambiarEstadoDistribuidoraRequest $request, Distribuidora $distributor, ServicioEstadoDistribuidora $servicio): DistribuidoraDetalleResource
    {
        Gate::authorize('changeStatus', $distributor);
        $datos = $request->validated();

        return new DistribuidoraDetalleResource($servicio->deshabilitar($distributor, $datos['reason'], $datos['lock_version'], $request->user()));
    }

    public function habilitar(CambiarEstadoDistribuidoraRequest $request, Distribuidora $distributor, ServicioEstadoDistribuidora $servicio): DistribuidoraDetalleResource
    {
        Gate::authorize('changeStatus', $distributor);
        $datos = $request->validated();

        return new DistribuidoraDetalleResource($servicio->habilitar($distributor, $datos['reason'], $datos['lock_version'], $request->user()));
    }
}
