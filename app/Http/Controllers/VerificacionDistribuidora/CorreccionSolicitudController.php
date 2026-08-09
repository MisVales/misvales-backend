<?php

namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerificacionDistribuidora\AplicarCorreccionSolicitudRequest;
use App\Http\Requests\VerificacionDistribuidora\FinalizarCorreccionesRequest;
use App\Http\Resources\VerificacionDistribuidora\DistributorApplicationResource;
use App\Services\VerificacionDistribuidora\ServicioConsultaExpedientes;
use App\Services\VerificacionDistribuidora\ServicioCorreccionSolicitud;

class CorreccionSolicitudController extends Controller
{
    public function __construct(
        private readonly ServicioCorreccionSolicitud $correccion,
        private readonly ServicioConsultaExpedientes $consulta,
    ) {}

    public function aplicarCorreccion(
        AplicarCorreccionSolicitudRequest $request,
        string $application,
    ): DistributorApplicationResource {
        $data = $request->validated();
        $this->correccion->aplicarCorreccion(
            $application,
            ApplicationCorrectionSection::from($data['seccion']),
            $data['campo'],
            $data['valor_observado'],
            $data['valor_corregido'],
            $data['motivo'],
            (string) $request->user()->id,
            (int) $data['lock_version'],
        );

        return new DistributorApplicationResource(
            $this->consulta->consultar($application, (string) $request->user()->id),
        );
    }

    public function finalizarCorrecciones(
        FinalizarCorreccionesRequest $request,
        string $application,
    ): DistributorApplicationResource {
        $data = $request->validated();
        $this->correccion->finalizarCorrecciones(
            $application,
            (string) $request->user()->id,
            (int) $data['lock_version'],
        );

        return new DistributorApplicationResource(
            $this->consulta->consultar($application, (string) $request->user()->id),
        );
    }
}
