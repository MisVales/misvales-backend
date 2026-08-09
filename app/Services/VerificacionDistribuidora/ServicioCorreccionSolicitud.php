<?php
namespace App\Services\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\ApplicationCorrection;
use App\Enums\ApplicationStatus;
use App\Enums\ApplicationCorrectionSection;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessException;
use Illuminate\Support\Arr;
use App\Helpers\AuditHelper;

class ServicioCorreccionSolicitud {
    
    public function listarDiferencias(string $applicationId, string $coordinatorId): array {
        $application = DistributorApplication::where('id', $applicationId)->where('coordinator_id', $coordinatorId)->first();
        if (!$application) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        
        $visit = VerificationVisit::with('mediaFiles')->where('application_id', $applicationId)->orderBy('created_at', 'desc')->first();
        if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
        
        $corrections = ApplicationCorrection::where('application_id', $applicationId)->where('verification_visit_id', $visit->id)->get();
        return [
            'application' => $application, 'visit' => $visit,
            'differences' => $visit->differences_payload['items'] ?? [], 'corrections_applied' => $corrections
        ];
    }

    public function aplicarCorreccion(
        string $applicationId, string $visitId, ApplicationCorrectionSection $section, 
        string $fieldPath, $newValuePayload, string $reason, string $coordinatorId, int $lockVersion
    ): ApplicationCorrection {
        return DB::transaction(function () use ($applicationId, $visitId, $section, $fieldPath, $newValuePayload, $reason, $coordinatorId, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (!$application) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            if ($application->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            if ($application->coordinator_id !== $coordinatorId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Intento de corrección', 'DENIED');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'No en correcciones.', 409);

            $visit = VerificationVisit::find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            
            $differences = $visit->differences_payload['items'] ?? [];
            $fieldWasReported = collect($differences)->contains(function ($diff) use ($section, $fieldPath) {
                return ($diff['section'] ?? '') === $section->value && ($diff['field'] ?? '') === $fieldPath;
            });
            if (!$fieldWasReported) throw new BusinessException('APPLICATION_CORRECTION_DIFFERENCE_NOT_FOUND', 'Campo no reportado.', 404);

            $appData = $application->applicant_data;
            $previousValuePayload = Arr::get($appData, $section->value . '.' . $fieldPath);
            Arr::set($appData, $section->value . '.' . $fieldPath, $newValuePayload);
            
            $application->applicant_data = $appData;
            $application->save();

            $correction = ApplicationCorrection::create([
                'application_id' => $application->id, 'verification_visit_id' => $visit->id,
                'section' => $section, 'field_path' => $fieldPath,
                'previous_value_payload' => json_encode($previousValuePayload),
                'new_value_payload' => json_encode($newValuePayload),
                'reason' => $reason, 'corrected_by' => $coordinatorId, 'corrected_at' => now()
            ]);

            AuditHelper::log('APPLICATION_CORRECTION_APPLIED', 'ApplicationCorrection', $correction->id, $coordinatorId, $application->branch_id, null, ['field' => $fieldPath], $reason, 'SUCCESS', $application->lock_version);
            return $correction;
        });
    }
    
    public function finalizarCorrecciones(string $applicationId, string $coordinatorId, int $lockVersion): void {
        DB::transaction(function () use ($applicationId, $coordinatorId, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (!$application) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            if ($application->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            if ($application->coordinator_id !== $coordinatorId) throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'No en correcciones.', 409);
            
            $visit = VerificationVisit::where('application_id', $applicationId)->orderBy('created_at', 'desc')->first();
            $differences = $visit->differences_payload['items'] ?? [];
            $correctionCount = ApplicationCorrection::where('application_id', $applicationId)->where('verification_visit_id', $visit->id)->count();
            if ($correctionCount < count($differences)) throw new BusinessException('APPLICATION_CORRECTIONS_PENDING', 'Faltan diferencias por corregir.', 409);

            $application->transitionTo(ApplicationStatus::COORDINATOR_EVALUATION, $coordinatorId, "Correcciones terminadas");

            AuditHelper::log('APPLICATION_CORRECTIONS_COMPLETED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Etapa terminada', 'SUCCESS', $application->lock_version);
        });
    }
}
