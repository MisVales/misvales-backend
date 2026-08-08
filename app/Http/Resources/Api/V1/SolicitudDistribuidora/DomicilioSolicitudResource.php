<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DomicilioSolicitudResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'is_current' => $this->is_current,
            'street' => $this->street,
            'exterior_number' => $this->exterior_number,
            'interior_number' => $this->interior_number,
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->postal_code,
            'municipality' => $this->municipality,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'housing_tenure' => $this->housing_tenure,
            'financing_status' => $this->financing_status,
            'width_meters' => $this->width_meters,
            'length_meters' => $this->length_meters,
            'built_area_square_meters' => $this->built_area_square_meters,
            'details_payload' => $this->details_payload,
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
