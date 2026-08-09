<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationCorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $difference = collect($this->visit?->differences_payload['items'] ?? [])->first(
            fn (array $item): bool => ($item['seccion'] ?? null) === ($this->section?->value ?? $this->section)
                && ($item['campo'] ?? null) === $this->field_path,
        );

        return [
            'id' => $this->id,
            'seccion' => $this->section?->value ?? $this->section,
            'campo' => $this->field_path,
            'valor_original' => $this->previous_value_payload,
            'valor_observado' => $difference['dato_observado'] ?? null,
            'valor_corregido' => $this->new_value_payload,
            'motivo' => $this->reason,
            'corregido_por' => $this->corrected_by,
            'fecha_correccion' => $this->corrected_at?->toIso8601String(),
        ];
    }
}
