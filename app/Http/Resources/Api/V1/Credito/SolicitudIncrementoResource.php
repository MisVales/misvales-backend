<?php

namespace App\Http\Resources\Api\V1\Credito;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitudIncrementoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credit_line_id' => $this->credit_line_id,
            'distributor_id' => $this->distributor_id,
            'requested_amount' => (string) $this->requested_amount,
            'recommended_amount' => $this->recommended_amount ? (string) $this->recommended_amount : null,
            'authorized_amount' => $this->authorized_amount ? (string) $this->authorized_amount : null,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
