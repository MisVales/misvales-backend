<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Resources;

use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductVersionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON Resource para versión de producto (C12).
 *
 * @mixin ProductVersionModel
 */
final class ProductVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProductVersionModel $version */
        $version = $this->resource;

        return [
            'product_id' => $version->product->public_id,
            'version_id' => $version->public_id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_to' => $version->effective_to?->toIso8601String(),
            'amount' => $version->amount,
            'loan_commission_rate' => $version->loan_commission_rate,
            'interest_rate_per_fortnight' => $version->interest_rate_per_fortnight,
            'insurance_amount' => $version->insurance_amount,
            'fortnight_count' => $version->fortnight_count,
            'reason' => $version->reason,
            'lock_version' => $version->lock_version,
            'created_at' => $version->created_at->toIso8601String(),
        ];
    }
}
