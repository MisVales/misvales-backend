<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationAuthorizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gerente_id' => $this->authorized_by,
            'decision' => $this->decision === ApplicationAuthorizationDecision::APPROVED
                ? 'AUTORIZADA'
                : 'RECHAZADA',
            'motivo' => $this->reason,
            'fecha_autorizacion' => $this->authorized_at?->toIso8601String(),
        ];
    }
}
