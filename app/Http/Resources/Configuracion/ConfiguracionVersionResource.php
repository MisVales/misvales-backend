<?php

namespace App\Http\Resources\Configuracion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfiguracionVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hideSensitive = false;
        if ($this->relationLoaded('definition') && $this->definition->is_sensitive) {
            // Aquí se comprobarían permisos. Si no los tiene:
            // $hideSensitive = !$request->user()->can('view-sensitive', $this->definition);
        }

        return [
            'id' => $this->id,
            'configuration_definition_id' => $this->configuration_definition_id,
            'version' => $this->version,
            'value' => $hideSensitive ? '***' : $this->value,
            'status' => $this->status->value,
            'effective_from' => $this->effective_from,
            'effective_to' => $this->effective_to,
            'reason' => $this->reason,
            'created_by' => $this->created_by,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
        ];
    }
}
