<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reportes\ServicioResumenInicioDistribuidora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResumenInicioDistribuidoraController extends Controller
{
    public function __invoke(Request $request, ServicioResumenInicioDistribuidora $service): JsonResponse
    {
        return response()->json(['data' => $service->obtener($request->user())]);
    }
}
