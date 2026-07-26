<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RiskSequenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $sequence = $this->resource;

        return [
            'id' => $sequence->id,
            'status' => $sequence->status->value,
            'breach_count' => $sequence->breach_count,
            'started_at' => $sequence->started_at->toIso8601String(),
            'last_incorporated_at' => $sequence->last_incorporated_at?->toIso8601String(),
            'reset_reason' => $sequence->reset_reason,
            'regularized_at' => $sequence->regularized_at?->toIso8601String(),
            'version' => $sequence->version,
            'relations' => $sequence->relations->map(fn ($relation) => [
                'relation_id' => $relation->relation_id,
                'position' => $relation->position,
                'overdue_balance' => $relation->overdue_balance_snapshot,
                'source_result' => $relation->source_result->value,
            ])->all(),
        ];
    }
}
