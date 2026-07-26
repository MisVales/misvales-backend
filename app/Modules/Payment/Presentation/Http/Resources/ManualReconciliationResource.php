<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de una conciliación manual. */
final class ManualReconciliationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'case_number' => $this->resource->case_number,
            'relation_id' => $this->resource->relation_id,
            'bank_movement_id' => $this->resource->bank_movement_id,
            'clarification_id' => $this->resource->clarification_id,
            'status' => $this->resource->status->value,
            'reason' => $this->resource->reason,
            'decision_reason' => $this->resource->decision_reason,
            'requested_at' => $this->resource->requested_at?->toIso8601String(),
            'decided_at' => $this->resource->decided_at?->toIso8601String(),
            'executed_at' => $this->resource->executed_at?->toIso8601String(),
            'lock_version' => $this->resource->lock_version,
        ];
    }
}
