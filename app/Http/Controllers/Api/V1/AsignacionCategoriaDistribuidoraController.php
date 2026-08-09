<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Distribuidora\AsignarCategoriaRequest;
use App\Http\Resources\Api\V1\Distribuidora\AsignacionCategoriaResource;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ServicioAsignacionCategoria;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AsignacionCategoriaDistribuidoraController extends Controller
{
    public function index(Distribuidora $distributor): AnonymousResourceCollection
    {
        Gate::authorize('viewCategoryHistory', $distributor);

        return AsignacionCategoriaResource::collection(
            $distributor->asignacionesCategoria()
                ->with('versionCategoria.category', 'asignadaPor')
                ->orderByDesc('starts_at')
                ->get(),
        );
    }

    public function store(
        AsignarCategoriaRequest $request,
        Distribuidora $distributor,
        ServicioAsignacionCategoria $servicio,
    ): AsignacionCategoriaResource {
        Gate::authorize('assignCategory', $distributor);

        return new AsignacionCategoriaResource(
            $servicio->asignar($distributor, $request->validated(), $request->user()),
        );
    }
}
