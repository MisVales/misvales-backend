<?php

namespace App\Http\Resources\Api\V1\Excedente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ExcedenteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'distributor_id' => $this->distributor_id,
            'distributor_name' => $this->whenLoaded('distributor', fn () => $this->distributor?->usuario?->name),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'origin_relation_id' => $this->origin_relation_id,
            'origin_relation_reference' => $this->whenLoaded('originRelation', fn () => $this->originRelation?->payment_reference),
            'bank_movement_id' => $this->bank_movement_id,
            'bank_folio' => $this->whenLoaded('bankMovement', fn () => $this->bankMovement?->bank_folio),
            'original_amount' => $this->original_amount,
            'available_amount' => $this->available_amount,
            'reserved_amount' => $this->reserved_amount,
            'status' => $this->status,
            'applications' => AplicacionExcedenteResource::collection($this->whenLoaded('applications')),
            'refund_requests' => DevolucionExcedenteResource::collection($this->whenLoaded('refundRequests')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
