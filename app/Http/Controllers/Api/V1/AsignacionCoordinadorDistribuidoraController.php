<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\AsignarCoordinadorRequest;
use App\Http\Resources\Api\V1\Distribuidora\AsignacionCoordinadorResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ServicioAsignacionCoordinador;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AsignacionCoordinadorDistribuidoraController extends Controller
{
    public function index(Distribuidora $distributor): AnonymousResourceCollection
    {
        Gate::authorize('viewCoordinatorHistory', $distributor);

        return AsignacionCoordinadorResource::collection($distributor->asignacionesCoordinador()->with(['coordinator', 'assignedBy'])->latest('valid_from')->get());
    }

    public function store(AsignarCoordinadorRequest $request, Distribuidora $distributor, ServicioAsignacionCoordinador $servicio): AsignacionCoordinadorResource
    {
        Gate::authorize('assignCoordinator', $distributor);
        $datos = $request->validated();

        return new AsignacionCoordinadorResource($servicio->asignar($distributor, $datos['coordinator_id'], $datos['reason'], $datos['lock_version'], $request->user()));
    }
}
