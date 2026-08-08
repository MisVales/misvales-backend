<?php
namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Services\VerificacionDistribuidora\ServicioCorreccionSolicitud;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\VerificacionDistribuidora\AplicarCorreccionSolicitudRequest;
use App\Http\Requests\VerificacionDistribuidora\FinalizarCorreccionesRequest;
use App\Enums\ApplicationCorrectionSection;

class CorreccionSolicitudController extends Controller {
    
    public function __construct(private ServicioCorreccionSolicitud $correccionService) {}

    public function listarDiferencias(string $applicationId) {
        $data = $this->correccionService->listarDiferencias($applicationId, auth()->id());
        return response()->json(['data' => ['application' => new \App\Http\Resources\VerificacionDistribuidora\DistributorApplicationResource($data['application']), 'visit' => new \App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource($data['visit']), 'differences' => $data['differences'], 'corrections_applied' => \App\Http\Resources\VerificacionDistribuidora\ApplicationCorrectionResource::collection($data['corrections_applied'])]]);
    }

    public function aplicarCorreccion(AplicarCorreccionSolicitudRequest $request, string $applicationId, string $visitId = null) {
        $data = $request->validated();
        // Since visitId is not in route, extract it from body or infer it in service. Actually I will use $data['visit_id'] assuming it's validated
        $correction = $this->correccionService->aplicarCorreccion(
            $applicationId, 
            $data['visit_id'] ?? $visitId ?? throw new \InvalidArgumentException("Visit ID missing"), 
            ApplicationCorrectionSection::from($data['section']), 
            $data['field_path'], 
            $data['new_value'], 
            $data['reason'], 
            auth()->id(), 
            (int) $data['lock_version']
        );
        return (new \App\Http\Resources\VerificacionDistribuidora\ApplicationCorrectionResource($correction))->additional(['message' => 'Corrección aplicada.']);
    }

    public function finalizarCorrecciones(FinalizarCorreccionesRequest $request, string $applicationId) {
        $data = $request->validated();
        $this->correccionService->finalizarCorrecciones($applicationId, auth()->id(), (int) $data['lock_version']);
        return response()->json(['message' => 'Etapa de correcciones finalizada.'], 200);
    }
}
