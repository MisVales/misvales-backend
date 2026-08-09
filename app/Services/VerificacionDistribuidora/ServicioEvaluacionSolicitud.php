<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\ApplicationCorrection;
use App\Models\ApplicationEvaluation;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use Illuminate\Support\Facades\DB;

class ServicioEvaluacionSolicitud
{
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function evaluar(
        string $applicationId,
        ApplicationEvaluationResult $result,
        string $reason,
        string $coordinatorId,
        int $lockVersion,
    ): ApplicationEvaluation {
        return DB::transaction(function () use ($applicationId, $result, $reason, $coordinatorId, $lockVersion): ApplicationEvaluation {
            $application = DistributorApplication::query()->lockForUpdate()->find($applicationId);
            if ($application === null) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $this->acceso->exigirCoordinador($application, $coordinatorId);
            if ($application->status !== ApplicationStatus::COORDINATOR_EVALUATION) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_INVALID_STATE', 'El expediente no está listo para evaluación.', 409);
            }

            $visit = VerificationVisit::query()
                ->where('application_id', $application->id)
                ->where('status', VerificationVisitStatus::COMPLETED)
                ->latest('completed_at')
                ->first();

            if ($visit === null || $visit->result !== VerificationVisitResult::FAVORABLE) {
                throw new BusinessException('APPLICATION_EVALUATION_PHYSICAL_RESULT_INVALID', 'La visita física no fue favorable.', 409);
            }

            $pending = collect($visit->differences_payload['items'] ?? [])->contains(function (array $difference) use ($application, $visit): bool {
                return ! ApplicationCorrection::query()
                    ->where('application_id', $application->id)
                    ->where('verification_visit_id', $visit->id)
                    ->where('section', $difference['seccion'])
                    ->where('field_path', $difference['campo'])
                    ->exists();
            });

            if ($pending) {
                throw new BusinessException('APPLICATION_EVALUATION_DIFFERENCES_PENDING', 'Existen diferencias sin corregir.', 409);
            }

            if (ApplicationEvaluation::query()->where('application_id', $application->id)->exists()) {
                throw new BusinessException('APPLICATION_EVALUATION_ALREADY_EXISTS', 'El expediente ya fue evaluado.', 409);
            }

            $evaluation = new ApplicationEvaluation([
                'application_id' => $application->id,
                'verification_visit_id' => $visit->id,
                'reason' => $reason,
                'evaluated_by' => $coordinatorId,
                'evaluated_at' => now(),
            ]);
            $evaluation->forceFill(['result' => $result])->save();

            $nextStatus = $result === ApplicationEvaluationResult::COMPLIES
                ? ApplicationStatus::MANAGER_AUTHORIZATION
                : ApplicationStatus::TERMINATED_UNFAVORABLE;
            $application->transitionTo($nextStatus, $coordinatorId, "Evaluación: {$result->value}");

            AuditHelper::log(
                'APPLICATION_COORDINATOR_EVALUATED',
                'ApplicationEvaluation',
                $evaluation->id,
                $coordinatorId,
                $application->branch_id,
                new: ['result' => $result->value, 'application_status' => $nextStatus->value],
                reason: $reason,
                version: $application->lock_version,
            );

            return $evaluation;
        });
    }
}
