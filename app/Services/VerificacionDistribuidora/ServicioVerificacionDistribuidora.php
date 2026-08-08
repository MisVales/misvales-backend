<?php
namespace App\Services\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\MediaFile;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Enums\VerificationVisitResult;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessException;
use Illuminate\Database\Eloquent\Collection;
use App\Helpers\AuditHelper;

class ServicioVerificacionDistribuidora {
    
    public function consultarAsignadas(string $verifierId): Collection {
        return VerificationVisit::with('application')
            ->where('verifier_id', $verifierId)
            ->whereIn('status', [VerificationVisitStatus::ASSIGNED, VerificationVisitStatus::IN_PROGRESS])
            ->get();
    }

    public function consultarVisita(string $visitId, string $verifierId): VerificationVisit {
        $visit = VerificationVisit::with(['application', 'mediaFiles'])
            ->where('id', $visitId)
            ->where('verifier_id', $verifierId)
            ->first();
        if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
        return $visit;
    }

    public function iniciarVisita(string $visitId, string $verifierId, int $lockVersion): VerificationVisit {
        return DB::transaction(function () use ($visitId, $verifierId, $lockVersion) {
            $visit = VerificationVisit::lockForUpdate()->find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            if ($visit->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            
            $application = DistributorApplication::lockForUpdate()->find($visit->application_id);

            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, null, 'Intento de inicio no autorizado');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No asignado a este verificador.', 403);
            }
            if ($visit->status === VerificationVisitStatus::IN_PROGRESS) throw new BusinessException('VERIFICATION_VISIT_ALREADY_STARTED', 'La visita ya está en progreso.', 409);
            if ($visit->status === VerificationVisitStatus::COMPLETED) throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'La visita ya está completada.', 409);
            if ($visit->status !== VerificationVisitStatus::ASSIGNED) throw new BusinessException('DISTRIBUTOR_APPLICATION_INVALID_STATE', 'La visita no está asignada.', 409);

            $application->transitionTo(ApplicationStatus::PHYSICAL_VERIFICATION, $verifierId, "Inicio de visita");
            
            $visit->forceFill([
                'status' => VerificationVisitStatus::IN_PROGRESS,
                'started_at' => now(),
            ])->save();

            AuditHelper::log('VERIFICATION_VISIT_STARTED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, ['status' => 'IN_PROGRESS'], null, 'SUCCESS', $visit->lock_version);

            return $visit;
        });
    }

    public function actualizarVisita(string $visitId, string $verifierId, ?float $lat, ?float $lng, ?float $accuracy, int $lockVersion): void {
        DB::transaction(function () use ($visitId, $verifierId, $lat, $lng, $accuracy, $lockVersion) {
            $visit = VerificationVisit::lockForUpdate()->find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            if ($visit->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            if ($visit->verifier_id !== $verifierId) throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No asignado a este verificador.', 403);
            if ($visit->status === VerificationVisitStatus::ASSIGNED) throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'Visita no iniciada.', 409);
            if ($visit->status === VerificationVisitStatus::COMPLETED) throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita completada.', 409);
            
            $visit->forceFill([
                'latitude' => (string)$lat,
                'longitude' => (string)$lng,
                'location_accuracy_meters' => (string)$accuracy
            ])->save();
        });
    }

    public function registrarDiferencias(string $visitId, string $verifierId, array $differencesPayload, int $lockVersion): void {
        DB::transaction(function () use ($visitId, $verifierId, $differencesPayload, $lockVersion) {
            $visit = VerificationVisit::lockForUpdate()->find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            if ($visit->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'VerificationVisit', $visit->id, $verifierId, null, null, null, 'Intento de registro de diferencias no autorizado');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::ASSIGNED) throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'Visita no iniciada.', 409);
            if ($visit->status === VerificationVisitStatus::COMPLETED) throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita completada.', 409);
            
            $visit->forceFill(['differences_payload' => $differencesPayload])->save();
            
            AuditHelper::log('VERIFICATION_DIFFERENCE_RECORDED', 'VerificationVisit', $visit->id, $verifierId, null, null, ['has_differences' => $differencesPayload['has_differences'] ?? false], null, 'SUCCESS', $visit->lock_version);
        });
    }

    public function finalizarVisita(
        string $visitId, 
        string $verifierId, 
        string $result,
        ?string $observations,
        int $lockVersion
    ): void {
        DB::transaction(function () use ($visitId, $verifierId, $result, $observations, $lockVersion) {
            $visit = VerificationVisit::lockForUpdate()->find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            if ($visit->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            
            $application = DistributorApplication::lockForUpdate()->find($visit->application_id);
            if (!$application) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            
            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, null, 'Intento de finalización no autorizado');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::ASSIGNED) throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'Visita no iniciada.', 409);
            if ($visit->status === VerificationVisitStatus::COMPLETED) throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita ya completada.', 409);

            $hasEvidence = MediaFile::where('verification_visit_id', $visit->id)->exists();
            if (!$hasEvidence) throw new BusinessException('VERIFICATION_VISIT_EVIDENCE_REQUIRED', 'No se puede finalizar la visita sin evidencias.', 409);

            $resEnum = VerificationVisitResult::tryFrom($result);
            if (!$resEnum) throw new BusinessException('VERIFICATION_VISIT_RESULT_INVALID', 'Resultado inválido.', 422);

            if ($resEnum === VerificationVisitResult::UNFAVORABLE && empty($observations)) {
                throw new BusinessException('VERIFICATION_VISIT_RESULT_INVALID', 'Se requieren observaciones para visitas desfavorables.', 422);
            }

            $visit->forceFill([
                'status' => VerificationVisitStatus::COMPLETED,
                'completed_at' => now(),
                'visited_at' => now(),
                'result' => $resEnum,
                'observations' => $observations,
            ])->save();

            AuditHelper::log('VERIFICATION_VISIT_COMPLETED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, ['result' => $resEnum->value], $observations, 'SUCCESS', $visit->lock_version);

            $appStatus = $resEnum === VerificationVisitResult::FAVORABLE 
                ? ApplicationStatus::COORDINATOR_EVALUATION 
                : ApplicationStatus::TERMINATED_UNFAVORABLE;
            
            $differencesPayload = $visit->differences_payload ?? [];
            if ($resEnum === VerificationVisitResult::FAVORABLE && !empty($differencesPayload) && ($differencesPayload['has_differences'] ?? false)) {
                $appStatus = ApplicationStatus::COORDINATOR_CORRECTION;
            }

            $application->transitionTo($appStatus, $verifierId, "Visita completada: " . $resEnum->value);

            if ($appStatus === ApplicationStatus::TERMINATED_UNFAVORABLE) {
                AuditHelper::log('APPLICATION_TERMINATED_UNFAVORABLE', 'DistributorApplication', $application->id, $verifierId, $application->branch_id, null, null, $observations, 'SUCCESS', $application->lock_version);
            }
        });
    }
}
