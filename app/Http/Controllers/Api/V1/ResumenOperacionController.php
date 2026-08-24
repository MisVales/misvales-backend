<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reportes\ServicioResumenOperacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResumenOperacionController extends Controller
{
    public function __invoke(Request $request, ServicioResumenOperacion $service): JsonResponse
    {
        abort_unless(
            $request->user()->hasRole('cashier')
                || $request->user()->hasRole('branch_manager')
                || $request->user()->hasRole('general_manager')
                || $request->user()->hasRole('admin'),
            403,
        );

        return response()->json(['data' => $service->obtener($request->user())]);
    }
}
