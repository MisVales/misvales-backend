<?php
namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Services\VerificacionDistribuidora\ServicioAutorizacionSolicitud;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\VerificacionDistribuidora\AutorizarSolicitudRequest;
use App\Enums\ApplicationAuthorizationDecision;

class AutorizacionSolicitudController extends Controller {
    
    public function __construct(private ServicioAutorizacionSolicitud $autorizacionService) {}

    public function consultarAutorizacion(string $applicationId) {
        $auth = $this->autorizacionService->consultarAutorizacion($applicationId);
        return new \App\Http\Resources\VerificacionDistribuidora\ApplicationAuthorizationResource($auth);
    }

    public function autorizar(AutorizarSolicitudRequest $request, string $applicationId) {
        $data = $request->validated();
        
        $decision = $data['decision'] ?? ApplicationAuthorizationDecision::APPROVED->value;

        if ($decision === ApplicationAuthorizationDecision::APPROVED->value) {
            $auth = $this->autorizacionService->autorizar(
                $applicationId, 
                auth()->id(), 
                $data['reason'],
                (float) $data['initial_credit_line_amount'],
                (int) $data['lock_version'],
            );
            $msg = 'Solicitud autorizada exitosamente.';
        } else {
            $auth = $this->autorizacionService->rechazar(
                $applicationId, 
                auth()->id(), 
                $data['reason'], (int) $data['lock_version']
            );
            $msg = 'Solicitud rechazada exitosamente.';
        }

        return response()->json(['message' => $msg, 'data' => $auth]);
    }
}
