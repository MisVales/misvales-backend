<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $difference = $this->difference_index !== null
            ? ($this->visit?->differences_payload['items'][$this->difference_index] ?? [])
            : [];
        $previousPayload = $this->previous_value_payload;
        $acceptedPayload = $this->new_value_payload;
        $previous = is_array($previousPayload) ? ($previousPayload['value'] ?? null) : $previousPayload;
        $accepted = is_array($acceptedPayload) ? ($acceptedPayload['value'] ?? null) : $acceptedPayload;

        $decryptIfEncrypted = function ($val) {
            if (is_string($val) && str_starts_with($val, 'eyJpdi')) {
                try {
                    return \Illuminate\Support\Facades\Crypt::decryptString($val);
                } catch (\Throwable) {
                    return $val;
                }
            }
            return $val;
        };

        $previous = $decryptIfEncrypted($previous);
        $accepted = $decryptIfEncrypted($accepted);

        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'verification_visit_id' => $this->verification_visit_id,
            'section' => $this->section->value ?? $this->section,
            'field_path' => $this->field_path,
            'target_record_id' => $this->target_record_id,
            'difference_index' => $this->difference_index,
            'reason' => $this->reason,
            'declared_value' => $difference['declared_value'] ?? $previous,
            'observed_value' => $difference['observed_value'] ?? $accepted,
            'accepted_value' => $accepted,
            'corrected_by' => $this->corrected_by,
            'corrected_by_name' => $this->coordinator?->name,
            'corrected_at' => $this->corrected_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // No exponemos previous_value_payload o new_value_payload si hay riesgo de ciphertext/hmac
        ];
    }
}
