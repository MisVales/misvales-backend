<?php

namespace App\Http\Resources\Api\V1\Cliente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MovimientoCarteraResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'entry_type' => $this->entry_type,
            'amount' => $this->amount,
            'informational_status' => $this->informational_status,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'due_date' => $this->due_date?->format('Y-m-d'),
            'last_payment_at' => $this->last_payment_at?->toIso8601String(),
            'note' => $this->note,
            'related_voucher_id' => $this->related_voucher_id,
            'recorded_by' => $this->recorded_by,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
