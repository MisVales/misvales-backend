<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Credito\LineaCreditoResource;
use App\Services\Credito\ServicioConsultaLineaCredito;
use Illuminate\Http\Request;

class LineaCreditoController extends Controller
{
    protected ServicioConsultaLineaCredito $consultaLinea;

    public function __construct(ServicioConsultaLineaCredito $consultaLinea)
    {
        $this->consultaLinea = $consultaLinea;
    }

    public function me(Request $request)
    {
        $usuario = $request->user();

        $datos = $this->consultaLinea->consultarPorDistribuidora($usuario);

        return new LineaCreditoResource($datos);
    }
}
