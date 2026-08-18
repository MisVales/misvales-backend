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
            'application_id' => $this->application_id,
            'verification_visit_id' => $this->verification_visit_id,
            'result' => $this->result->value ?? $this->result,
            'reason' => $this->reason,
            'evaluated_by' => $this->evaluated_by,
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'visit' => new VerificationVisitResource($this->whenLoaded('visit')),
        ];
    }
}
