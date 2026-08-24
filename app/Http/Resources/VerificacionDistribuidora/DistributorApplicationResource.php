<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_number' => $this->application_number,
            'branch_id' => $this->branch_id,
            'status' => $this->status->value ?? $this->status,
            'pending_sections' => $this->pending_sections,
            'coordinator_id' => $this->coordinator_id,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->id,
                'name' => $this->branch?->name,
            ]),
            'coordinator' => $this->whenLoaded('coordinator', fn () => [
                'id' => $this->coordinator?->id,
                'name' => $this->coordinator?->name,
            ]),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'applicant' => $this->whenLoaded('datosPersonales', fn () => [
                'full_name' => trim(implode(' ', array_filter([
                    $this->datosPersonales?->first_name,
                    $this->datosPersonales?->first_last_name,
                    $this->datosPersonales?->second_last_name,
                ]))),
            ]),
            // Desgloses solicitados:
            'verification_visits' => VerificationVisitResource::collection($this->whenLoaded('verificationVisits')),
            'corrections' => ApplicationCorrectionResource::collection($this->whenLoaded('corrections')),
            'evaluations' => ApplicationEvaluationResource::collection($this->whenLoaded('evaluations')),
            'latest_evaluation' => new ApplicationEvaluationResource($this->whenLoaded('latestEvaluation')),
            'authorization' => new ApplicationAuthorizationResource($this->whenLoaded('authorization')),
        ];
    }
}
