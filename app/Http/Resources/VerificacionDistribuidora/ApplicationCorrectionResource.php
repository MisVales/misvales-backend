<?php
namespace App\Http\Resources\VerificacionDistribuidora;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationCorrectionResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'verification_visit_id' => $this->verification_visit_id,
            'section' => $this->section->value ?? $this->section,
            'field_path' => $this->field_path,
            'reason' => $this->reason,
            'corrected_by' => $this->corrected_by,
            'corrected_at' => $this->corrected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // No exponemos previous_value_payload o new_value_payload si hay riesgo de ciphertext/hmac
        ];
    }
}
