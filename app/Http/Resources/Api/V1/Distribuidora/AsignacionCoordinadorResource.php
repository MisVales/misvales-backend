<?php

namespace App\Http\Resources\Api\V1\Distribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AsignacionCoordinadorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'coordinator' => $this->coordinator ? ['id' => $this->coordinator->id, 'name' => $this->coordinator->name] : null,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'valid_from' => $this->valid_from?->toIso8601String(),
            'valid_to' => $this->valid_to?->toIso8601String(),
            'assignment_reason' => $this->assignment_reason,
            'end_reason' => $this->end_reason,
            'assigned_by' => $this->assignedBy ? ['id' => $this->assignedBy->id, 'name' => $this->assignedBy->name] : null,
        ];
    }
}
