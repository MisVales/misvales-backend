<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Resources;

use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models\ExcessApplicationModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExcessApplicationModel */
final class ExcessApplicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'relacion_id' => $this->relation_id,
            'importe_aplicado' => (new Money((string) $this->amount))->publicValue(),
            'disponible_antes' => (new Money((string) $this->available_before))->publicValue(),
            'disponible_despues' => (new Money((string) $this->available_after))->publicValue(),
            'fecha_tecnica' => $this->applied_at?->toIso8601String(),
            'fecha_efectiva' => $this->effective_at?->toIso8601String(),
            'clasificacion_temporal' => null,
        ];
    }
}
