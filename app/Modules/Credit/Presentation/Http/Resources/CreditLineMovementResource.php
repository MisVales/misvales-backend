<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Resources;

use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineMovementModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditLineMovementModel */
final class CreditLineMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'type' => $this->type->value,
            'total_delta' => (new Money($this->total_delta))->format(),
            'used_delta' => (new Money($this->used_delta))->format(),
            'total_before' => (new Money($this->total_before))->format(),
            'total_after' => (new Money($this->total_after))->format(),
            'used_before' => (new Money($this->used_before))->format(),
            'used_after' => (new Money($this->used_after))->format(),
            'available_before' => (new Money($this->available_before))->format(),
            'available_after' => (new Money($this->available_after))->format(),
            'source' => ['type' => $this->source_type, 'id' => $this->source_id],
            'reason' => $this->reason,
            'configuration_snapshot' => $this->configuration_snapshot,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
