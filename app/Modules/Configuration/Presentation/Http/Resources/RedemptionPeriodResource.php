<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Resources;

use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON Resource para un periodo de canje (C12).
 *
 * @mixin RedemptionPeriodModel
 */
final class RedemptionPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RedemptionPeriodModel $period */
        $period = $this->resource;

        return [
            'id' => $period->public_id,
            'status' => $period->status,
            'starts_at' => $period->starts_at->toIso8601String(),
            'ends_at' => $period->ends_at->toIso8601String(),
            'created_by' => $period->created_by,
            'published_by' => $period->published_by,
            'published_at' => $period->published_at?->toIso8601String(),
            'reason' => $period->reason,
            'lock_version' => $period->lock_version,
            'created_at' => $period->created_at->toIso8601String(),
        ];
    }
}
