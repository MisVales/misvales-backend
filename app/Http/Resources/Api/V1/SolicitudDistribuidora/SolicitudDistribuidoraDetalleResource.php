<?php

namespace App\Http\Resources\Api\V1\SolicitudDistribuidora;

use App\Http\Resources\VerificacionDistribuidora\ApplicationAuthorizationResource;
use App\Http\Resources\VerificacionDistribuidora\ApplicationCorrectionResource;
use App\Http\Resources\VerificacionDistribuidora\ApplicationEvaluationResource;
use App\Http\Resources\VerificacionDistribuidora\VerificationVisitResource;
use App\Models\MediaFileBinding;
use Illuminate\Http\Request;

final class SolicitudDistribuidoraDetalleResource extends SolicitudDistribuidoraResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'personal_data' => $this->whenLoaded('datosPersonales', fn () => $this->datosPersonales === null ? null : new DatosPersonalesSolicitudResource($this->datosPersonales)),
            'family_members' => FamiliarSolicitudResource::collection($this->whenLoaded('familiares')),
            'residences' => DomicilioSolicitudResource::collection($this->whenLoaded('domicilios')),
            'vehicles' => VehiculoSolicitudResource::collection($this->whenLoaded('vehiculos')),
            'assets_liabilities' => PatrimonioSolicitudResource::collection($this->whenLoaded('patrimonio')),
            'employments' => EmpleoSolicitudResource::collection($this->whenLoaded('empleos')),
            'commercial_credits' => CreditoComercialSolicitudResource::collection($this->whenLoaded('creditosComerciales')),
            'verification_visits' => VerificationVisitResource::collection($this->whenLoaded('verificationVisits')),
            'corrections' => ApplicationCorrectionResource::collection($this->whenLoaded('corrections')),
            'evaluations' => ApplicationEvaluationResource::collection($this->whenLoaded('evaluations')),
            'latest_evaluation' => new ApplicationEvaluationResource($this->whenLoaded('latestEvaluation')),
            'authorization' => new ApplicationAuthorizationResource($this->whenLoaded('authorization')),
            'has_vehicle_evidence' => $this->hasEvidence('VEHICLE_EVIDENCE'),
            'has_assets_evidence' => $this->hasEvidence('ASSET_EVIDENCE'),
            'has_commercial_credit_evidence' => $this->hasEvidence('COMMERCIAL_EVIDENCE'),
        ]);
    }

    private function hasEvidence(string $purpose): bool
    {
        return MediaFileBinding::query()
            ->where('owner_type', 'distributor_application')
            ->where('owner_id', $this->id)
            ->where('purpose', $purpose)
            ->exists();
    }
}
