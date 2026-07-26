<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Resources;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ModificationRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return $this->resource;
        }

        /** @var DataChangeRequestModel $model */
        $model = $this->resource;

        return [
            'request_id' => $model->id,
            'voucher_id' => $model->voucher_id,
            'client_id' => $model->client_id,
            'branch_id' => $model->branch_id,
            'requested_by' => $model->requested_by,
            'operation' => $model->operation->value,
            'fields' => $model->authorized_fields,
            'reason' => $model->reason,
            'status' => $model->status->value,
            'decided_by' => $model->decided_by,
            'decision_reason' => $model->decision_reason,
            'requested_at' => $model->requested_at->toIso8601String(),
            'decided_at' => $model->decided_at?->toIso8601String(),
            'used_at' => $model->used_at?->toIso8601String(),
            'expired_at' => $model->expired_at?->toIso8601String(),
            'lock_version' => $model->lock_version,
        ];
    }
}
