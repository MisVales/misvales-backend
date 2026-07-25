<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Resources;

use App\Modules\Credit\Domain\ValueObjects\Money;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CreditIncreaseRequestModel */
final class CreditIncreaseRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'folio' => $this->folio,
            'distributor_id' => $this->relationLoaded('distributor') ? $this->distributor->public_id : $this->distributor_id,
            'branch_id' => $this->branch_id,
            'coordinator_id' => $this->coordinator_id,
            'requested_amount' => (new Money($this->requested_amount))->format(),
            'recommended_amount' => $this->recommended_amount === null ? null : (new Money($this->recommended_amount))->format(),
            'authorized_amount' => $this->authorized_amount === null ? null : (new Money($this->authorized_amount))->format(),
            'origin' => [
                'type' => $this->origin_type->value,
                'product_amount' => $this->product_amount === null ? null : (new Money($this->product_amount))->format(),
                'required_difference' => $this->required_difference === null ? null : (new Money($this->required_difference))->format(),
            ],
            'line_snapshot' => [
                'total_authorized' => (new Money($this->total_authorized_snapshot))->format(),
                'used_balance' => (new Money($this->used_balance_snapshot))->format(),
                'available_balance' => (new Money($this->available_balance_snapshot))->format(),
                'lock_version' => $this->credit_line_version_snapshot,
            ],
            'status' => $this->status->value,
            'reasons' => [
                'request' => $this->request_reason,
                'coordinator' => $this->coordinator_reason,
                'manager' => $this->manager_reason,
            ],
            'actors' => [
                'requested_by' => $this->requested_by_user_id,
                'reviewed_by' => $this->reviewed_by_user_id,
                'decided_by' => $this->decided_by_user_id,
            ],
            'requested_at' => $this->requested_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'restriction_id' => $this->relationLoaded('restriction') ? $this->restriction?->public_id : $this->restriction_id,
            'lock_version' => $this->lock_version,
        ];
    }
}
