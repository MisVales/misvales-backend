<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de una aplicación financiera y su desglose. */
final class PaymentAllocationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'movement_id' => $this->resource->bank_movement_id,
            'relation_id' => $this->resource->relation_id,
            'source_type' => $this->resource->source_type->value,
            'received_amount' => $this->resource->received_amount,
            'applied_amount' => $this->resource->applied_amount,
            'excess_amount' => $this->resource->excess_amount,
            'breakdown' => [
                'late_fee' => $this->resource->late_fee_amount,
                'interest' => $this->resource->interest_amount,
                'insurance' => $this->resource->insurance_amount,
                'loan_commission' => $this->resource->loan_commission_amount,
                'capital' => $this->resource->capital_amount,
            ],
            'credit_line_recovered' => $this->resource->capital_amount,
            'balance_before' => $this->resource->balance_before,
            'balance_after' => $this->resource->balance_after,
            'effective_at' => $this->resource->effective_at?->toIso8601String(),
            'applied_at' => $this->resource->applied_at?->toIso8601String(),
            'application_mode' => $this->resource->application_mode->value,
            'items' => PaymentAllocationItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
