<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->file_type,
            'nombre_original' => $this->original_name,
            'mime_type' => $this->mime_type,
            'tamano_bytes' => $this->size_bytes,
            'fecha_carga' => $this->created_at?->toIso8601String(),
            'cargado_por' => $this->uploaded_by,
        ];
    }
}
