<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'solicitud_id' => $this->application_id,
            'verificador_id' => $this->verifier_id,
            'estado' => $this->status?->value ?? $this->status,
            'resultado_fisico' => $this->result?->value ?? $this->result,
            'observaciones_generales' => $this->observations,
            'fecha_asignacion' => $this->assigned_at?->toIso8601String(),
            'fecha_inicio' => $this->started_at?->toIso8601String(),
            'fecha_fin' => $this->completed_at?->toIso8601String(),
            'diferencias' => $this->differences_payload['items'] ?? [],
            'evidencias' => MediaFileResource::collection($this->whenLoaded('mediaFiles')),
            'lock_version' => $this->lock_version,
        ];
    }
}
