<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'coordinador_id' => $this->evaluated_by,
            'dictamen' => $this->result?->value ?? $this->result,
            'motivo' => $this->reason,
            'fecha_evaluacion' => $this->evaluated_at?->toIso8601String(),
        ];
    }
}
