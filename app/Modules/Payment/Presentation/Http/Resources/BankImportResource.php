<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de una importación bancaria. */
final class BankImportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'business_date' => $this->resource->business_date?->toDateString(),
            'original_name' => $this->resource->original_name,
            'status' => $this->resource->status->value,
            'counts' => [
                'total' => $this->resource->total_rows,
                'valid' => $this->resource->valid_rows,
                'invalid' => $this->resource->invalid_rows,
                'reconciled' => $this->resource->reconciled_rows,
                'unreconciled' => $this->resource->unreconciled_rows,
                'duplicate' => $this->resource->duplicate_rows,
            ],
            'error_summary' => $this->resource->error_summary,
            'processing_started_at' => $this->resource->processing_started_at?->toIso8601String(),
            'processing_finished_at' => $this->resource->processing_finished_at?->toIso8601String(),
            'retry_count' => $this->resource->retry_count,
            'lock_version' => $this->resource->lock_version,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
