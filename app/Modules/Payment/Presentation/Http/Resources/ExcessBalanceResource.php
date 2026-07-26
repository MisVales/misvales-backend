<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público del libro y disponibilidad de un excedente. */
final class ExcessBalanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'origin_relation_id' => $this->resource->origin_relation_id,
            'bank_movement_id' => $this->resource->bank_movement_id,
            'original_amount' => $this->resource->original_amount,
            'available_amount' => $this->resource->available_amount,
            'applied_amount' => $this->resource->applied_amount,
            'reserved_refund_amount' => $this->resource->reserved_refund_amount,
            'refunded_amount' => $this->resource->refunded_amount,
            'status' => $this->resource->status->value,
            'decision' => $this->resource->decision,
            'lock_version' => $this->resource->lock_version,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
