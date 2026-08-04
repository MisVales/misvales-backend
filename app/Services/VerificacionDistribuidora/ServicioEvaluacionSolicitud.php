<?php
namespace App\Services\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\ApplicationEvaluation;
use App\Models\ApplicationCorrection;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\ApplicationEvaluationResult;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;

class ServicioEvaluacionSolicitud {
    public function consultarEvaluacion(string $applicationId, string $coordinatorId): ?ApplicationEvaluation {
        return ApplicationEvaluation::with('visit')->where('application_id', $applicationId)->where('evaluated_by', $coordinatorId)->first();
    }

    public function evaluar(
        string $applicationId, string $visitId, ApplicationEvaluationResult $result, 
        string $reason, string $coordinatorId, ?array $payload, int $lockVersion
    ): ApplicationEvaluation {
        return DB::transaction(function () use ($applicationId, $visitId, $result, $reason, $coordinatorId, $payload, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (!$application) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            if ($application->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            if ($application->coordinator_id !== $coordinatorId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Intento de evaluación', 'DENIED');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_EVALUATION) throw new BusinessException('DISTRIBUTOR_APPLICATION_INVALID_STATE', 'Estado inválido.', 409);

            $visit = VerificationVisit::find($visitId);
            if (!$visit) throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            if ($visit->status !== VerificationVisitStatus::COMPLETED) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_READY_FOR_VERIFICATION', 'Visita no terminada.', 409);

            if ($result === ApplicationEvaluationResult::COMPLIES) {
                if ($visit->result !== VerificationVisitResult::FAVORABLE) throw new BusinessException('APPLICATION_EVALUATION_PHYSICAL_RESULT_INVALID', 'Visita desfavorable.', 409);
                $differences = $visit->differences_payload['items'] ?? [];
                if (!empty($differences)) {
                    $correctionCount = ApplicationCorrection::where('application_id', $application->id)->where('verification_visit_id', $visit->id)->count();
                    if ($correctionCount < count($differences)) throw new BusinessException('APPLICATION_EVALUATION_DIFFERENCES_PENDING', 'Diferencias sin resolver.', 409);
                }
            }

            if (ApplicationEvaluation::where('application_id', $application->id)->exists()) {
                throw new BusinessException('APPLICATION_EVALUATION_ALREADY_EXISTS', 'Ya evaluado.', 409);
            }

            $eval = new ApplicationEvaluation([
                'application_id' => $application->id, 'verification_visit_id' => $visit->id,
                'reason' => $reason, 'evaluation_payload' => $payload,
                'evaluated_by' => $coordinatorId, 'evaluated_at' => now()
            ]);
            $eval->forceFill(['result' => $result])->save();

            $newStatus = $result === ApplicationEvaluationResult::COMPLIES ? ApplicationStatus::MANAGER_AUTHORIZATION : ApplicationStatus::TERMINATED_UNFAVORABLE;
            $application->transitionTo($newStatus, $coordinatorId, "Evaluación: " . $result->value);

            AuditHelper::log('APPLICATION_COORDINATOR_EVALUATED', 'ApplicationEvaluation', $eval->id, $coordinatorId, $application->branch_id, null, ['result' => $result->value], $reason, 'SUCCESS', $application->lock_version);
            
            if ($newStatus === ApplicationStatus::MANAGER_AUTHORIZATION) {
                AuditHelper::log('APPLICATION_SENT_TO_MANAGER', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Enviado a gerente', 'SUCCESS', $application->lock_version);
            } else {
                AuditHelper::log('APPLICATION_TERMINATED_UNFAVORABLE', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, $reason, 'SUCCESS', $application->lock_version);
            }

            return $eval;
        });
    }
}
