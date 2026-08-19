<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SolicitudDistribuidora\CatalogoVehiculosSolicitud;
use Illuminate\Http\JsonResponse;

final class CatalogoVehiculosSolicitudController extends Controller
{
    public function __invoke(CatalogoVehiculosSolicitud $catalogo): JsonResponse
    {
        return response()->json(['data' => $catalogo->obtener()]);
    }
}
