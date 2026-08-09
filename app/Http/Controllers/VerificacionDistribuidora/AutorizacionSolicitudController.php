<?php

namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\VerificacionDistribuidora\AutorizarSolicitudRequest;
use App\Http\Resources\VerificacionDistribuidora\DistributorApplicationResource;
use App\Services\VerificacionDistribuidora\ServicioAutorizacionSolicitud;
use App\Services\VerificacionDistribuidora\ServicioConsultaExpedientes;

class AutorizacionSolicitudController extends Controller
{
    public function __construct(
        private readonly ServicioAutorizacionSolicitud $autorizacion,
        private readonly ServicioConsultaExpedientes $consulta,
    ) {}

    public function autorizar(AutorizarSolicitudRequest $request, string $application): DistributorApplicationResource
    {
        $data = $request->validated();
        $decision = $data['decision'] === 'AUTORIZADA'
            ? ApplicationAuthorizationDecision::APPROVED
            : ApplicationAuthorizationDecision::REJECTED;

        $this->autorizacion->decidir(
            $application,
            (string) $request->user()->id,
            $decision,
            $data['motivo'],
            (int) $data['lock_version'],
        );

        return new DistributorApplicationResource(
            $this->consulta->consultar($application, (string) $request->user()->id),
        );
    }
}
