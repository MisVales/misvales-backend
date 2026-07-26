<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Resources;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RiskProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;
        $actor = $request->user();
        $cashier = $actor instanceof User && $actor->role_code === RoleCode::CASHIER->value;

        return [
            'id' => $profile->id,
            'distributor_id' => $profile->relationLoaded('distributor') ? $profile->distributor->public_id : null,
            'branch_id' => $profile->relationLoaded('branch') ? $profile->branch->public_id : null,
            'coordinator_id' => $this->when(! $cashier, $profile->relationLoaded('coordinator') ? $profile->coordinator?->public_id : null),
            'consecutive_breaches' => $this->when(! $cashier, $profile->consecutive_breaches),
            'overdue_balance' => $this->when(! $cashier, $profile->overdue_balance),
            'financially_regularized_at' => $this->when(! $cashier, $profile->financially_regularized_at?->toIso8601String()),
            'delinquency_status' => $profile->delinquency_status->value,
            'blocked_for_new_vouchers' => $profile->blocked_for_new_vouchers,
            'profile_status' => $this->when(! $cashier, $profile->profile_status->value),
            'version' => $profile->lock_version,
            'last_evaluated_at' => $this->when(! $cashier, $profile->last_evaluated_at?->toIso8601String()),
            'delinquency_applied_at' => $profile->delinquency_applied_at?->toIso8601String(),
        ];
    }
}
