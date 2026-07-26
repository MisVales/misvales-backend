<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Resources;

use App\Modules\Points\Infrastructure\Persistence\Models\PointRedemptionRequestModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class PointRedemptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $redemption = $this->resource;
        if (! $redemption instanceof PointRedemptionRequestModel) {
            throw new LogicException('PointRedemptionResource requiere una solicitud de canje.');
        }
        $redemption->loadMissing(['distributor', 'branchSnapshot', 'period']);

        return [
            'id' => $redemption->id,
            'public_folio' => $redemption->public_folio,
            'distributor_id' => $redemption->distributor->public_id,
            'period_id' => $redemption->period->public_id,
            'branch_id_snapshot' => $redemption->branchSnapshot->public_id,
            'requested_points' => $redemption->requested_points,
            'authorized_points' => $redemption->authorized_points,
            'point_value_snapshot' => $redemption->point_value_snapshot,
            'cash_amount' => $redemption->cash_amount,
            'status' => $redemption->status->value,
            'requested_at' => $redemption->requested_at->toIso8601String(),
            'decided_at' => $redemption->decided_at?->toIso8601String(),
            'decision_reason' => $redemption->decision_reason,
            'completed_at' => $redemption->completed_at?->toIso8601String(),
        ];
    }
}
