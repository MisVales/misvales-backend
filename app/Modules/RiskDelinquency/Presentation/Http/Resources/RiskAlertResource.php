<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RiskAlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $alert = $this->resource;

        return [
            'id' => $alert->alert_number,
            'type' => $alert->alert_type->value,
            'breach_count' => $alert->breach_count,
            'overdue_balance' => $alert->overdue_balance_snapshot,
            'status' => $alert->status->value,
            'detected_at' => $alert->detected_at->toIso8601String(),
            'resolved_at' => $alert->resolved_at?->toIso8601String(),
            'relations' => $alert->relations->map(fn ($relation) => [
                'relation_id' => $relation->relation_id,
                'position' => $relation->position,
                'cut_at' => $relation->cut_at->toIso8601String(),
                'due_at' => $relation->due_at->toIso8601String(),
                'source_result' => $relation->source_result->value,
                'overdue_balance' => $relation->overdue_balance_snapshot,
                'source_version' => $relation->source_version,
            ])->all(),
        ];
    }
}
