<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Resources;

use App\Modules\Configuration\Infrastructure\Persistence\Models\RedemptionPeriodModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class RedemptionPeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $period = $this->resource;
        if (! $period instanceof RedemptionPeriodModel) {
            throw new LogicException('RedemptionPeriodResource requiere un periodo de M03/M13.');
        }

        return [
            'id' => $period->public_id,
            'public_folio' => $period->getAttribute('public_folio'),
            'name' => $period->getAttribute('name'),
            'description' => $period->getAttribute('description'),
            'starts_at' => $period->starts_at->timezone('America/Monterrey')->toIso8601String(),
            'ends_at' => $period->ends_at->timezone('America/Monterrey')->toIso8601String(),
            'timezone' => 'America/Monterrey',
            'status' => $period->status,
            'version' => (int) ($period->getAttribute('version') ?? 1),
            'published_at' => $period->published_at?->toIso8601String(),
            'reason' => $period->reason,
        ];
    }
}
