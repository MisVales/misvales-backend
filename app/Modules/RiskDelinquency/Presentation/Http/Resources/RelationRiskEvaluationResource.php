<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RelationRiskEvaluationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $evaluation = $this->resource;

        return [
            'id' => $evaluation->id,
            'relation_id' => $evaluation->relation_id,
            'cut_id' => $evaluation->cut_id,
            'cut_at' => $evaluation->cut_at->toIso8601String(),
            'due_at' => $evaluation->due_at->toIso8601String(),
            'source_result' => $evaluation->source_result?->value,
            'overdue_balance' => $evaluation->overdue_balance_snapshot,
            'status' => $evaluation->evaluation_status->value,
            'source_version' => $evaluation->source_version,
            'sequence_position' => $evaluation->sequence_position,
            'evaluated_at' => $evaluation->evaluated_at->toIso8601String(),
        ];
    }
}
