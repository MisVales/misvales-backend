<?php

namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerificacionDistribuidora\AdjuntarEvidenciaRequest;
use App\Http\Resources\VerificacionDistribuidora\MediaFileResource;
use App\Services\VerificacionDistribuidora\ServicioEvidenciaVerificacion;

class EvidenciaVerificacionController extends Controller
{
    public function __construct(private ServicioEvidenciaVerificacion $evidenciaService) {}

    public function adjuntarEvidencia(AdjuntarEvidenciaRequest $request, string $visitId)
    {
        $data = $request->validated();
        $media = $this->evidenciaService->adjuntarEvidencia($visitId, auth()->id(), $request->file('file'), $data['file_type'], (int) $data['lock_version']);

        return (new MediaFileResource($media))->additional(['message' => 'Evidencia adjuntada.']);
    }

    public function consultarEvidencia(string $visitId)
    {
        $media = $this->evidenciaService->consultarEvidencia($visitId);

        return response()->json(['data' => $media], 200);
    }

    public function descargarEvidencia(string $mediaId)
    {
        return $this->evidenciaService->descargarEvidencia($mediaId, auth()->id());
    }

    public function eliminarEvidenciaAbierta(string $mediaId)
    {
        $this->evidenciaService->eliminarEvidenciaAbierta($mediaId, auth()->id());

        return response()->json(['message' => 'Evidencia eliminada exitosamente.'], 200);
    }
}
