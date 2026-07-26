<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Resources;

use App\Modules\Points\Infrastructure\Persistence\Models\PointLedgerEntryModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class PointMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $movement = $this->resource;
        if (! $movement instanceof PointLedgerEntryModel) {
            throw new LogicException('PointMovementResource requiere una entrada del libro.');
        }

        return [
            'id' => $movement->id,
            'type' => $movement->type->value,
            'direction' => $movement->direction->value,
            'points' => $movement->points,
            'balance_before' => $movement->balance_before,
            'balance_after' => $movement->balance_after,
            'reserved_before' => $movement->reserved_before,
            'reserved_after' => $movement->reserved_after,
            'relation_id' => $movement->relation_id,
            'redemption_request_id' => $movement->redemption_request_id,
            'rule_code' => $movement->rule_code,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
        ];
    }
}
