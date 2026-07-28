<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Resultado resumido sin CURP, cuenta, documentos ni domicilio. */
final class VoucherSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'voucher_id' => $this->resource->id,
            'folio' => $this->resource->folio,
            'type' => $this->resource->type->value,
            'status' => $this->resource->status->value,
            'client_name' => $this->resource->client_name_snapshot,
            'capital' => $this->resource->capital_amount,
            'generated_at' => $this->resource->generated_at?->toIso8601String(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
