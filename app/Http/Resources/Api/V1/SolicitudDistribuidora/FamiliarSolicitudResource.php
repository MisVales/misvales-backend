<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FamiliarSolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'relationship' => $this->relationship,
            'first_name' => $this->first_name, 'first_last_name' => $this->first_last_name,
            'second_last_name' => $this->second_last_name, 'birth_date' => $this->birth_date?->format('Y-m-d'),
            'declared_age' => $this->declared_age, 'school_name' => $this->school_name,
            'is_family_reference' => $this->is_family_reference, 'details_payload' => $this->details_payload,
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
