<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EmpleoSolicitudResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'employer_name' => $this->employer_name, 'job_title' => $this->job_title,
            'started_at' => $this->started_at?->format('Y-m-d'), 'ended_at' => $this->ended_at?->format('Y-m-d'),
            'is_current' => $this->is_current, 'reference_payload' => $this->reference_payload,
            'details_payload' => $this->details_payload,
            'application_lock_version' => $this->when($this->application_lock_version !== null, $this->application_lock_version),
        ];
    }
}
