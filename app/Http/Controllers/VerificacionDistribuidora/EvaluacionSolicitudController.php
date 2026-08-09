<?php

namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Enums\ApplicationEvaluationResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerificacionDistribuidora\EvaluarSolicitudRequest;
use App\Http\Resources\VerificacionDistribuidora\DistributorApplicationResource;
use App\Services\VerificacionDistribuidora\ServicioConsultaExpedientes;
use App\Services\VerificacionDistribuidora\ServicioEvaluacionSolicitud;

class EvaluacionSolicitudController extends Controller
{
    public function __construct(
        private readonly ServicioEvaluacionSolicitud $evaluacion,
        private readonly ServicioConsultaExpedientes $consulta,
    ) {}

    public function evaluar(EvaluarSolicitudRequest $request, string $application): DistributorApplicationResource
    {
        $data = $request->validated();
        $this->evaluacion->evaluar(
            $application,
            ApplicationEvaluationResult::from($data['dictamen']),
            $data['motivo'],
            (string) $request->user()->id,
            (int) $data['lock_version'],
        );

        return new DistributorApplicationResource(
            $this->consulta->consultar($application, (string) $request->user()->id),
        );
    }
}
