<?php

namespace App\Http\Resources\Api\V1\Excedente;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AplicacionExcedenteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'relation_id' => $this->relation_id,
            'relation_reference' => $this->whenLoaded('relation', fn () => $this->relation?->payment_reference),
            'payment_id' => $this->payment_id,
            'amount' => $this->amount,
            'balance_before' => $this->balance_before,
            'balance_after' => $this->balance_after,
            'process' => $this->process,
            'applied_by' => $this->applied_by,
            'idempotency_key' => $this->idempotency_key,
            'applied_at' => $this->applied_at?->toIso8601String(),
        ];
    }
}
