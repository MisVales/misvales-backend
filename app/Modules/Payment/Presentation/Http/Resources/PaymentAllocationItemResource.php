<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de una partida incluida en una aplicación de pago. */
final class PaymentAllocationItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'relation_item_id' => $this->resource->relation_item_id,
            'voucher_id' => $this->resource->voucher_id,
            'breakdown' => [
                'late_fee' => $this->resource->late_fee_amount,
                'interest' => $this->resource->interest_amount,
                'insurance' => $this->resource->insurance_amount,
                'loan_commission' => $this->resource->loan_commission_amount,
                'capital' => $this->resource->capital_amount,
            ],
            'pending_before' => $this->resource->pending_before,
            'pending_after' => $this->resource->pending_after,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
