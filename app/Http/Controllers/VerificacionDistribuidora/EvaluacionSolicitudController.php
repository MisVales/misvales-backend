<?php
namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Services\VerificacionDistribuidora\ServicioEvaluacionSolicitud;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\VerificacionDistribuidora\EvaluarSolicitudRequest;
use App\Enums\ApplicationEvaluationResult;

class EvaluacionSolicitudController extends Controller {
    
    public function __construct(private ServicioEvaluacionSolicitud $evaluacionService) {}

    public function consultarEvaluacion(string $applicationId) {
        $eval = $this->evaluacionService->consultarEvaluacion($applicationId, auth()->id());
        return new \App\Http\Resources\VerificacionDistribuidora\ApplicationEvaluationResource($eval);
    }

    public function evaluar(EvaluarSolicitudRequest $request, string $applicationId) {
        $data = $request->validated();
        $eval = $this->evaluacionService->evaluar(
            $applicationId, 
            $data['visit_id'],
            ApplicationEvaluationResult::from($data['result']), 
            $data['reason'], 
            auth()->id(),
            $data['payload'] ?? null, (int) $data['lock_version']
        );
        return response()->json(['message' => 'Evaluación registrada exitosamente.', 'data' => $eval]);
    }
}
