<?php

namespace App\Http\Resources\Api\V1\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsignacionCategoriaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_version_id' => $this->category_version_id,
            'category' => $this->versionCategoria ? [
                'id' => $this->versionCategoria->category_id,
                'name' => $this->versionCategoria->name,
                'version' => $this->versionCategoria->version,
                'profit_rate' => $this->versionCategoria->profit_percentage,
            ] : null,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'assigned_by' => $this->assigned_by,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
