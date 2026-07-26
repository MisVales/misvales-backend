<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de una aclaración sin rutas privadas de evidencia. */
final class ClarificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'case_number' => $this->resource->case_number,
            'relation_id' => $this->resource->relation_id,
            'reported_amount' => $this->resource->reported_amount,
            'reported_date' => $this->resource->reported_date?->toDateString(),
            'reported_reference' => $this->resource->reported_reference,
            'reported_bank_folio' => $this->resource->reported_bank_folio,
            'description' => $this->resource->description,
            'status' => $this->resource->status->value,
            'linked_movement_id' => $this->resource->linked_movement_id,
            'has_evidence' => $this->resource->evidence_media_file_id !== null,
            'lock_version' => $this->resource->lock_version,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
