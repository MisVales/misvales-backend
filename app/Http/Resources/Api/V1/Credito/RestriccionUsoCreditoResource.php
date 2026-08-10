<?php

namespace App\Http\Resources\Api\V1\Credito;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RestriccionUsoCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credit_line_id' => $this->credit_line_id,
            'restriction_percentage' => (string) $this->restriction_percentage,
            'restricted_amount' => (string) $this->restricted_amount,
            'status' => $this->status,
            'release_reason' => $this->release_reason,
            'released_at' => $this->released_at?->toIso8601String(),
            'released_by' => $this->released_by,
        ];
    }
}
