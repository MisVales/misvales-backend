<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Resources;

use App\Modules\Configuration\Infrastructure\Persistence\Models\CategoryVersionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON Resource para versión de categoría.
 *
 * @mixin CategoryVersionModel
 */
final class CategoryVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CategoryVersionModel $version */
        $version = $this->resource;

        return [
            'category_id' => $version->category->public_id,
            'version_id' => $version->public_id,
            'version_number' => $version->version_number,
            'name' => $version->name,
            'description' => $version->description,
            'distributor_profit_rate' => $version->distributor_profit_rate,
            'status' => $version->status,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_to' => $version->effective_to?->toIso8601String(),
            'reason' => $version->reason,
            'lock_version' => $version->lock_version,
            'created_at' => $version->created_at->toIso8601String(),
        ];
    }
}
