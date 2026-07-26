<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Resources;

use App\Modules\Points\Infrastructure\Persistence\Models\PointsRunModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class PointsRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        if (! $run instanceof PointsRunModel) {
            throw new LogicException('PointsRunResource requiere una ejecución de M13.');
        }

        return [
            'id' => $run->id,
            'public_folio' => $run->public_folio,
            'status' => $run->status->value,
            'period_start' => $run->period_start?->toIso8601String(),
            'period_end' => $run->period_end?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'total_candidates' => (int) $run->getAttribute('total_candidates'),
            'processed_count' => (int) $run->getAttribute('processed_count'),
            'earned_count' => (int) $run->getAttribute('earned_count'),
            'penalized_count' => (int) $run->getAttribute('penalized_count'),
            'no_change_count' => (int) $run->getAttribute('no_change_count'),
            'blocked_count' => (int) $run->getAttribute('blocked_count'),
            'error_count' => (int) $run->getAttribute('error_count'),
            'initiated_by_type' => $run->getAttribute('initiated_by_type'),
        ];
    }
}
