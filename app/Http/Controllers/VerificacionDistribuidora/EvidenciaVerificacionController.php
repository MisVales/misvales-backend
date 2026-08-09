<?php

namespace App\Http\Controllers\VerificacionDistribuidora;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerificacionDistribuidora\AdjuntarEvidenciaRequest;
use App\Http\Resources\VerificacionDistribuidora\MediaFileResource;
use App\Services\VerificacionDistribuidora\ServicioEvidenciaVerificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvidenciaVerificacionController extends Controller
{
    public function __construct(private readonly ServicioEvidenciaVerificacion $evidencia) {}

    public function adjuntarEvidencia(AdjuntarEvidenciaRequest $request, string $visit): JsonResponse
    {
        $data = $request->validated();

        return (new MediaFileResource($this->evidencia->adjuntarEvidencia(
            $visit,
            (string) $request->user()->id,
            $request->file('file'),
            $data['tipo'],
            (int) $data['lock_version'],
        )))->response()->setStatusCode(201);
    }

    public function descargarEvidencia(Request $request, string $visit, string $evidence)
    {
        return $this->evidencia->descargarEvidencia(
            $visit,
            $evidence,
            (string) $request->user()->id,
        );
    }

    public function eliminarEvidenciaAbierta(
        Request $request,
        string $visit,
        string $evidence,
    ): JsonResponse {
        $data = $request->validate(['lock_version' => 'required|integer|min:1']);
        $this->evidencia->eliminarEvidenciaAbierta(
            $visit,
            $evidence,
            (string) $request->user()->id,
            (int) $data['lock_version'],
        );

        return response()->json(status: 204);
    }
}
