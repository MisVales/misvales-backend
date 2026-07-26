<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de un movimiento bancario. */
final class BankMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'bank_import_id' => $this->resource->bank_import_id,
            'row_number' => $this->resource->row_number,
            'payment_reference' => $this->resource->payment_reference_raw,
            'amount' => $this->resource->amount,
            'paid_at' => $this->resource->paid_at?->toIso8601String(),
            'bank_folio' => $this->resource->bank_folio_raw,
            'concept' => $this->resource->concept_raw,
            'status' => $this->resource->status->value,
            'validation_errors' => $this->resource->validation_errors,
            'matched_relation_id' => $this->resource->matched_relation_id,
            'duplicate_of_id' => $this->resource->duplicate_of_id,
            'result_reason' => $this->resource->result_reason,
            'processed_at' => $this->resource->processed_at?->toIso8601String(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
