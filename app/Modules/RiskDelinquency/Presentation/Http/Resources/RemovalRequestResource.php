<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RemovalRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $removal = $this->resource;

        return [
            'id' => $removal->request_number,
            'status' => $removal->status->value,
            'overdue_balance' => $removal->overdue_balance_snapshot,
            'prepared_reason' => $removal->prepared_reason,
            'decision_reason' => $removal->decision_reason,
            'prepared_at' => $removal->prepared_at->toIso8601String(),
            'decided_at' => $removal->decided_at?->toIso8601String(),
            'invalidated_at' => $removal->invalidated_at?->toIso8601String(),
            'version' => $removal->lock_version,
        ];
    }
}
