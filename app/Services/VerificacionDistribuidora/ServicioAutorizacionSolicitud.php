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
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServicioAutorizacionSolicitud
{
    public function consultarAutorizacion(string $applicationId, string $managerId): ?ApplicationAuthorization
    {
        $application = DistributorApplication::query()->whereKey($applicationId)->first();
        if ($application === null) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }
        $manager = User::query()->find($managerId);
        $isGlobalManager = $manager?->hasRole('general_manager') === true;
        $isBranchManager = $manager?->hasRole('branch_manager') === true
            && $manager->hasScopeForBranch($application->branch_id);
        if (! $isGlobalManager && ! $isBranchManager) {
            if ($manager === null || (! $manager->hasRole('general_manager') && ! $manager->hasRole('branch_manager'))) {
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No tienes permiso para consultar autorizaciones.', 403);
            }
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }

        return ApplicationAuthorization::with('manager')->where('application_id', $applicationId)->first();
    }

    public function autorizar(string $applicationId, string $managerId, string $reason, string $initialCreditLine, int $lockVersion): ApplicationAuthorization
    {
        if (bccomp($initialCreditLine, '0.0000', 4) <= 0) {
            throw new BusinessException('APPLICATION_INITIAL_CREDIT_LINE_INVALID', 'La línea inicial autorizada debe ser mayor que cero.', 422);
        }

        return $this->decidir($applicationId, $managerId, ApplicationAuthorizationDecision::APPROVED, $reason, $initialCreditLine, $lockVersion);
    }

    public function rechazar(string $applicationId, string $managerId, string $reason, int $lockVersion): ApplicationAuthorization
    {
        return $this->decidir($applicationId, $managerId, ApplicationAuthorizationDecision::REJECTED, $reason, null, $lockVersion);
    }

    private function decidir(string $applicationId, string $managerId, ApplicationAuthorizationDecision $decision, string $reason, ?string $initialCreditLine, int $lockVersion): ApplicationAuthorization
    {
        return DB::transaction(function () use ($applicationId, $managerId, $decision, $reason, $initialCreditLine, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }
            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $manager = User::find($managerId);
            $isGeneralManager = $manager?->hasRole('general_manager') === true;
            $isBranchManager = $manager?->hasRole('branch_manager') === true
                && $manager->hasScopeForBranch($application->branch_id);

            if ($manager === null || (! $isGeneralManager && ! $isBranchManager)) {
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No tienes permiso para autorizar solicitudes.', 403);
            }

            if (! $isGeneralManager && in_array($managerId, [$application->coordinator_id, $application->created_by], true)) {
                throw new BusinessException('SEGREGATION_OF_DUTIES_VIOLATION', 'Quien participó en la solicitud no puede autorizarla.', 403);
            }

            $creator = User::find($application->created_by);
            $creatorIsBranchManager = $creator && method_exists($creator, 'hasRole') && $creator->hasRole('branch_manager') && ! $creator->hasRole('general_manager');

            if ($creatorIsBranchManager && ! $isGeneralManager) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $managerId, $application->branch_id, null, null, 'Intento de autorización de solicitud creada por gerente', 'DENIED');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'Solo el gerente general puede autorizar una solicitud creada por un gerente de sucursal.', 403);
            }

            if ($application->status !== ApplicationStatus::MANAGER_AUTHORIZATION) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION', 'Estado inválido.', 409);
            }

            $evaluation = ApplicationEvaluation::where('application_id', $application->id)->latest('evaluated_at')->first();
            if (! $evaluation || $evaluation->result !== ApplicationEvaluationResult::COMPLIES) {
                throw new BusinessException('APPLICATION_AUTHORIZATION_NOT_ALLOWED', 'Evaluación no cumple.', 403);
            }

            if (ApplicationAuthorization::where('application_id', $application->id)->exists()) {
                throw new BusinessException('APPLICATION_AUTHORIZATION_ALREADY_EXISTS', 'Ya dictaminado.', 409);
            }

            $auth = new ApplicationAuthorization([
                'application_id' => $application->id, 'initial_credit_line_amount' => $initialCreditLine,
                'reason' => $reason, 'authorized_by' => $managerId, 'authorized_at' => now(),
            ]);
            $auth->forceFill(['decision' => $decision])->save();

            $newStatus = $decision === ApplicationAuthorizationDecision::APPROVED ? ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION : ApplicationStatus::REJECTED;
            $application->transitionTo($newStatus, $managerId, 'Decisión gerencial: '.$decision->value);

            if ($decision === ApplicationAuthorizationDecision::APPROVED) {
                AuditHelper::log('APPLICATION_MANAGER_APPROVED', 'ApplicationAuthorization', $auth->id, $managerId, $application->branch_id, null, ['decision' => 'APPROVED', 'initial_credit_line_amount' => $initialCreditLine], $reason, 'SUCCESS', $application->lock_version);
            } else {
                AuditHelper::log('APPLICATION_MANAGER_REJECTED', 'ApplicationAuthorization', $auth->id, $managerId, $application->branch_id, null, null, $reason, 'SUCCESS', $application->lock_version);
            }

            return $auth;
        });
    }
}
