<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\DistributorApplication;
use Illuminate\Support\Facades\DB;

class ServicioAutorizacionSolicitud
{
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function decidir(
        string $applicationId,
        string $managerId,
        ApplicationAuthorizationDecision $decision,
        string $reason,
        int $lockVersion,
    ): ApplicationAuthorization {
        return DB::transaction(function () use ($applicationId, $managerId, $decision, $reason, $lockVersion): ApplicationAuthorization {
            $application = DistributorApplication::query()->lockForUpdate()->find($applicationId);
            if ($application === null) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $this->acceso->exigirGerente($application, $managerId);
            if ($application->status !== ApplicationStatus::MANAGER_AUTHORIZATION) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION', 'El expediente no está listo para autorización.', 409);
            }

            $evaluation = ApplicationEvaluation::query()->where('application_id', $application->id)->first();
            if ($evaluation === null || $evaluation->result !== ApplicationEvaluationResult::COMPLIES) {
                throw new BusinessException('APPLICATION_AUTHORIZATION_NOT_ALLOWED', 'La evaluación no es favorable.', 409);
            }

            if (ApplicationAuthorization::query()->where('application_id', $application->id)->exists()) {
                throw new BusinessException('APPLICATION_AUTHORIZATION_ALREADY_EXISTS', 'El expediente ya tiene decisión final.', 409);
            }

            $authorization = new ApplicationAuthorization([
                'application_id' => $application->id,
                'reason' => $reason,
                'authorized_by' => $managerId,
                'authorized_at' => now(),
            ]);
            $authorization->forceFill([
                'decision' => $decision,
                'initial_credit_line_amount' => null,
            ])->save();

            $application->manager_id = $managerId;
            $nextStatus = $decision === ApplicationAuthorizationDecision::APPROVED
                ? ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION
                : ApplicationStatus::REJECTED;
            $application->transitionTo($nextStatus, $managerId, "Decisión gerencial: {$decision->value}");

            AuditHelper::log(
                $decision === ApplicationAuthorizationDecision::APPROVED
                    ? 'APPLICATION_MANAGER_AUTHORIZED'
                    : 'APPLICATION_MANAGER_REJECTED',
                'ApplicationAuthorization',
                $authorization->id,
                $managerId,
                $application->branch_id,
                new: [
                    'decision' => $decision === ApplicationAuthorizationDecision::APPROVED ? 'AUTORIZADA' : 'RECHAZADA',
                    'application_status' => $nextStatus->value,
                ],
                reason: $reason,
                version: $application->lock_version,
            );

            return $authorization;
        });
    }
}
