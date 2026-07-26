<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DelinquencyDecisionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $decision = $this->resource;

        return [
            'id' => $decision->decision_number,
            'decision' => $decision->decision,
            'overdue_balance' => $decision->overdue_balance_snapshot,
            'reason' => $decision->reason,
            'decided_role' => $decision->decided_role,
            'decided_at' => $decision->decided_at->toIso8601String(),
        ];
    }
}
