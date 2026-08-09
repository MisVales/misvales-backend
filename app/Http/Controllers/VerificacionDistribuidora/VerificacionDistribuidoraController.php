<?php
namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Services\VerificacionDistribuidora\ServicioVerificacionDistribuidora;
use App\Services\VerificacionDistribuidora\ServicioRevisionCoordinador;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\VerificacionDistribuidora\DevolverSolicitudCapturaRequest;
use App\Http\Requests\VerificacionDistribuidora\AsignarVerificadorRequest;
use App\Http\Requests\VerificacionDistribuidora\IniciarVisitaRequest;
use App\Http\Requests\VerificacionDistribuidora\ActualizarVisitaRequest;
use App\Http\Requests\VerificacionDistribuidora\FinalizarVisitaRequest;
use Illuminate\Http\Request;

class VerificacionDistribuidoraController extends Controller {
    
    public function __construct(
        private ServicioVerificacionDistribuidora $verificacionService,
        private ServicioRevisionCoordinador $revisionService
    ) {}

    // ---- Métodos de Coordinador ----

    public function devolverACaptura(DevolverSolicitudCapturaRequest $request, string $applicationId) {
        $data = $request->validated();
        $this->revisionService->devolverACaptura($applicationId, auth()->id(), $data['reason'], $data['pending_sections'], (int) $data['lock_version']);
        return response()->json(['message' => 'Solicitud devuelta a captura exitosamente.'], 200);
    }

    public function asignarVerificador(AsignarVerificadorRequest $request, string $applicationId) {
        $data = $request->validated();
        $this->revisionService->asignarVerificador($applicationId, auth()->id(), $data['verifier_id'], (int) $data['lock_version']);
        return response()->json(['message' => 'Verificador asignado exitosamente.'], 200);
    }

    // ---- Métodos de Verificador ----

    public function consultarAsignadas() {
        $visits = $this->verificacionService->consultarAsignadas(auth()->id());
        return \App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource::collection($visits);
    }

    public function consultarVisita(string $visitId) {
        $visit = $this->verificacionService->consultarVisita($visitId, auth()->id());
        return new \App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource($visit);
    }

    public function iniciarVisita(IniciarVisitaRequest $request, string $visitId) {
        $request->validated();
        $data = $request->validated();
        $visit = $this->verificacionService->iniciarVisita($visitId, auth()->id(), (int) $data['lock_version']);
        return (new \App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource($visit))->additional(['message' => 'Visita iniciada.']);
    }

    public function actualizarVisita(ActualizarVisitaRequest $request, string $visitId) {
        $data = $request->validated();
        $this->verificacionService->actualizarVisita($visitId, auth()->id(), $data['latitude'] ?? null, $data['longitude'] ?? null, $data['accuracy'] ?? null, (int) $data['lock_version']);
        return response()->json(['message' => 'Visita actualizada.'], 200);
    }

    public function registrarDiferencias(Request $request, string $visitId) {
        // En una app real usaríamos un FormRequest estricto para validar la estructura del JSON
        $differences = $request->validate([
            'differences_payload' => 'required|array',
            'lock_version' => 'required|integer'
        ]);
        $this->verificacionService->registrarDiferencias($visitId, auth()->id(), $differences['differences_payload'], (int) $differences['lock_version']);
        return response()->json(['message' => 'Diferencias registradas.'], 200);
    }

    public function finalizarVisita(FinalizarVisitaRequest $request, string $visitId) {
        $data = $request->validated();
        $this->verificacionService->finalizarVisita(
            $visitId, 
            auth()->id(), 
            $data['result'], 
            $data['observations'] ?? null, (int) $data['lock_version']
        );
        return response()->json(['message' => 'Visita finalizada exitosamente.'], 200);
    }
}
