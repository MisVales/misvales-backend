<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PatrimonioSolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'entry_type' => $this->entry_type, 'name' => $this->name,
            'amount' => $this->amount, 'outstanding_balance' => $this->outstanding_balance,
            'monthly_payment' => $this->monthly_payment, 'is_active' => $this->is_active,
            'details_payload' => $this->details_payload,
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
