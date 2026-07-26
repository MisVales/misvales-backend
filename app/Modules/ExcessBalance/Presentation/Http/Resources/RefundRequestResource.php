<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Resources;

use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RefundRequestModel */
final class RefundRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->request_number,
            'excedente_id' => $this->excess_balance_id,
            'importe_solicitado' => (new Money((string) $this->amount))->publicValue(),
            'estado' => $this->status->value,
            'solicitado_en' => $this->requested_at->toIso8601String(),
            'decidido_en' => $this->decided_at?->toIso8601String(),
            'completado_en' => $this->completed_at?->toIso8601String(),
            'fecha_devolucion' => $this->refund_date?->toDateString(),
            'metodo' => $this->refund_method,
            'referencia' => $this->refund_reference,
            'tiene_evidencia' => $this->evidence_media_file_id !== null,
            'lock_version' => (int) $this->lock_version,
        ];
    }
}
