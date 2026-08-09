<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CreditoComercialSolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'company_name' => $this->company_name,
            'credit_limit' => $this->credit_limit, 'is_current' => $this->is_current,
            'proof_reference' => $this->proof_reference, 'details_payload' => $this->details_payload,
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
