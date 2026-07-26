<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Resources;

use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReportDefinition */
final class ReportDefinitionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->publicContract();
    }
}
