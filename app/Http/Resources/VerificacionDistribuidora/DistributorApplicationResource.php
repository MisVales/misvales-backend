<?php

namespace App\Http\Resources\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DistributorApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = $this->masked_applicant_data ?? [];
        $personal = $data['personal_info'] ?? $data['personal_data'] ?? [];
        $name = trim(implode(' ', array_filter([
            $personal['first_name'] ?? $personal['nombre'] ?? null,
            $personal['last_name'] ?? $personal['apellido_paterno'] ?? null,
            $personal['second_last_name'] ?? $personal['apellido_materno'] ?? null,
        ])));

        return [
            'id' => $this->id,
            'folio' => $data['folio'] ?? null,
            'aspirante' => [
                'nombre_completo' => $name,
                'curp_enmascarado' => $personal['curp'] ?? '',
                'rfc_enmascarado' => $personal['rfc'] ?? '',
            ],
            'sucursal' => [
                'id' => $this->branch_id,
                'nombre' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            ],
            'coordinador_id' => $this->coordinator_id,
            'estado' => $this->estadoPublico(),
            'fecha_envio' => $this->created_at?->toIso8601String(),
            'avance' => $this->avance(),
            'datos_declarados' => $data,
            'visitas' => VerificationVisitResource::collection($this->whenLoaded('verificationVisits')),
            'correcciones' => ApplicationCorrectionResource::collection($this->whenLoaded('corrections')),
            'evaluacion' => $this->whenLoaded('evaluation', fn () => $this->evaluation === null
                ? null
                : new ApplicationEvaluationResource($this->evaluation)),
            'autorizacion' => $this->whenLoaded('authorization', fn () => $this->authorization === null
                ? null
                : new ApplicationAuthorizationResource($this->authorization)),
            'lock_version' => $this->lock_version,
        ];
    }

    private function estadoPublico(): string
    {
        return match ($this->status) {
            ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION => 'AUTORIZADA',
            ApplicationStatus::REJECTED => 'RECHAZADA',
            default => $this->status->value,
        };
    }

    private function avance(): int
    {
        return match ($this->status) {
            ApplicationStatus::COORDINATOR_REVIEW => 10,
            ApplicationStatus::VERIFIER_ASSIGNED => 25,
            ApplicationStatus::PHYSICAL_VERIFICATION => 45,
            ApplicationStatus::COORDINATOR_CORRECTION => 65,
            ApplicationStatus::COORDINATOR_EVALUATION => 75,
            ApplicationStatus::MANAGER_AUTHORIZATION => 90,
            ApplicationStatus::TERMINATED_UNFAVORABLE,
            ApplicationStatus::REJECTED,
            ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION => 100,
            default => 0,
        };
    }
}
