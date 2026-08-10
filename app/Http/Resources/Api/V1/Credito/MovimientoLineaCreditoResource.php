<?php

namespace App\Http\Resources\Api\V1\Credito;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoLineaCreditoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credit_line_id' => $this->credit_line_id,
            'movement_type' => $this->movement_type,
            'amount' => (string) $this->amount,
            'previous_balance' => (string) $this->previous_balance,
            'new_balance' => (string) $this->new_balance,
            'reference_id' => $this->reference_id,
            'reference_type' => $this->reference_type,
            'reason' => $this->reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
