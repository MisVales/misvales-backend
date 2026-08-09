<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Distribuidora;
use App\Services\Distribuidora\ServicioPreparacionActivacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PreparacionActivacionDistribuidoraController extends Controller
{
    public function solicitudes(Request $request, ServicioPreparacionActivacion $servicio): JsonResponse
    {
        Gate::authorize('viewAny', Distribuidora::class);
        abort_unless($request->user()->hasPermissionTo('distributors.activate'), 403);

        return response()->json(['data' => $servicio->solicitudesAutorizadas($request->user())]);
    }

    public function categorias(Request $request, ServicioPreparacionActivacion $servicio): JsonResponse
    {
        abort_unless($request->user()->hasPermissionTo('distributors.assign_category'), 403);

        return response()->json(['data' => $servicio->categoriasDisponibles()]);
    }
}
