<?php

namespace App\Http\Resources\Configuracion;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfiguracionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'value_type' => $this->value_type,
            'unit' => $this->unit,
            'is_required' => $this->is_required,
            'is_sensitive' => $this->is_sensitive,
            'status' => $this->status->value,
            'lock_version' => $this->lock_version,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'versions' => ConfiguracionVersionResource::collection($this->whenLoaded('versions')),
        ];
    }
}
