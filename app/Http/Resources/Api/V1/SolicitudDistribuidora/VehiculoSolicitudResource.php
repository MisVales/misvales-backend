<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class VehiculoSolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'vehicle_type' => $this->vehicle_type, 'brand' => $this->brand,
            'model' => $this->model, 'model_year' => $this->model_year,
            'ownership_status' => $this->ownership_status, 'details_payload' => $this->details_payload,
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
