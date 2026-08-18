<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationAuthorizationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'decision' => $this->decision->value ?? $this->decision,
            'reason' => $this->reason,
            // Cast importe a string
            'initial_credit_line_amount' => (string) $this->initial_credit_line_amount,
            'authorized_by' => $this->authorized_by,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
