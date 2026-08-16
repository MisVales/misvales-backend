<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use App\Http\Resources\Api\V1\SolicitudDistribuidora\SolicitudDistribuidoraDetalleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VerificationVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_id' => $this->application_id,
            'verifier_id' => $this->verifier_id,
            'assigned_by' => $this->assigned_by,
            'status' => $this->status->value ?? $this->status,
            'result' => $this->result->value ?? $this->result,
            'observations' => $this->observations,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'location_accuracy_meters' => $this->location_accuracy_meters,
            'differences_payload' => $this->differences_payload,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'visited_at' => $this->visited_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'application' => $this->whenLoaded('application', function () {
                if ($this->application->relationLoaded('datosPersonales')) {
                    return new SolicitudDistribuidoraDetalleResource($this->application);
                }

                return new DistributorApplicationResource($this->application);
            }),
            'media_files' => MediaFileResource::collection($this->whenLoaded('mediaFiles')),
        ];
    }
}
