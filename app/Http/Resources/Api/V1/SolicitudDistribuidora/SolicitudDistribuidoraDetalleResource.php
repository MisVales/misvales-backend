<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use Illuminate\Http\Request;

final class SolicitudDistribuidoraDetalleResource extends SolicitudDistribuidoraResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'section_declarations' => $this->section_declarations,
            'personal_data' => $this->whenLoaded('datosPersonales', fn () => $this->datosPersonales === null ? null : new DatosPersonalesSolicitudResource($this->datosPersonales)),
            'family_members' => FamiliarSolicitudResource::collection($this->whenLoaded('familiares')),
            'residences' => DomicilioSolicitudResource::collection($this->whenLoaded('domicilios')),
            'vehicles' => VehiculoSolicitudResource::collection($this->whenLoaded('vehiculos')),
            'assets_liabilities' => PatrimonioSolicitudResource::collection($this->whenLoaded('patrimonio')),
            'employments' => EmpleoSolicitudResource::collection($this->whenLoaded('empleos')),
            'commercial_credits' => CreditoComercialSolicitudResource::collection($this->whenLoaded('creditosComerciales')),
            'verification_visits' => \App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource::collection($this->whenLoaded('verificationVisits')),
            'corrections' => \App\Http\Resources\VerificacionDistribuidora\ApplicationCorrectionResource::collection($this->whenLoaded('corrections')),
            'evaluations' => \App\Http\Resources\VerificacionDistribuidora\ApplicationEvaluationResource::collection($this->whenLoaded('evaluations')),
            'latest_evaluation' => new \App\Http\Resources\VerificacionDistribuidora\ApplicationEvaluationResource($this->whenLoaded('latestEvaluation')),
            'authorization' => new \App\Http\Resources\VerificacionDistribuidora\ApplicationAuthorizationResource($this->whenLoaded('authorization')),
        ]);
    }
}
