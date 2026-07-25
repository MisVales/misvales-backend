<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Presentation\Http\Resources;

use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON Resource para la versión vigente de una configuración (C12).
 *
 * @mixin ConfigurationVersionModel
 */
final class ConfigurationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ConfigurationVersionModel $version */
        $version = $this->resource;
        $definition = $version->definition;

        return [
            'key' => $definition->key,
            'type' => $definition->type,
            'version' => [
                'id' => $version->public_id,
                'number' => $version->version_number,
                'effective_from' => $version->effective_from?->toIso8601String(),
                'effective_to' => $version->effective_to?->toIso8601String(),
            ],
            'value' => $version->value,
        ];
    }
}
