<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Resources;

use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON Resource para una versión de configuración con historial.
 *
 * @mixin ConfigurationVersionModel
 */
final class ConfigurationVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ConfigurationVersionModel $version */
        $version = $this->resource;

        return [
            'id' => $version->public_id,
            'version_number' => $version->version_number,
            'value' => $version->value,
            'status' => $version->status,
            'effective_from' => $version->effective_from?->toIso8601String(),
            'effective_to' => $version->effective_to?->toIso8601String(),
            'created_by' => $version->created_by,
            'published_by' => $version->published_by,
            'published_at' => $version->published_at?->toIso8601String(),
            'reason' => $version->reason,
            'lock_version' => $version->lock_version,
            'created_at' => $version->created_at->toIso8601String(),
        ];
    }
}
