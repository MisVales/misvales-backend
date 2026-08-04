<?php
namespace App\Services\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\ApplicationEvaluation;
use App\Models\ApplicationAuthorization;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationAuthorizationDecision;
use Illuminate\Support\Facades\DB;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Helpers\AuditHelper;

class ServicioAutorizacionSolicitud {
    public function consultarAutorizacion(string $applicationId): ?ApplicationAuthorization {
        return ApplicationAuthorization::with('manager')->where('application_id', $applicationId)->first();
    }

    public function autorizar(string $applicationId, string $managerId, string $reason, float $initialCreditLine, int $lockVersion): ApplicationAuthorization {
        if (empty($initialCreditLine) || $initialCreditLine <= 0) throw new BusinessException('APPLICATION_INITIAL_CREDIT_LINE_INVALID', 'Línea inválida.', 422);
        return $this->decidir($applicationId, $managerId, ApplicationAuthorizationDecision::APPROVED, $reason, $initialCreditLine, $lockVersion);
    }

    public function rechazar(string $applicationId, string $managerId, string $reason, int $lockVersion): ApplicationAuthorization {
        return $this->decidir($applicationId, $managerId, ApplicationAuthorizationDecision::REJECTED, $reason, null, $lockVersion);
    }

    private function decidir(string $applicationId, string $managerId, ApplicationAuthorizationDecision $decision, string $reason, ?float $initialCreditLine, int $lockVersion): ApplicationAuthorization {
        return DB::transaction(function () use ($applicationId, $managerId, $decision, $reason, $initialCreditLine, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (!$application) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            if ($application->lock_version !== $lockVersion) throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            
            $manager = User::find($managerId);
            $isGeneralManager = method_exists($manager, 'hasRole') ? $manager->hasRole('general_manager') : false;
            $isBranchManager = method_exists($manager, 'hasRole') ? $manager->hasRole('branch_manager') : true;

            if (!$isGeneralManager && (!$isBranchManager || $manager->branch_id !== $application->branch_id)) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $managerId, $application->branch_id, null, null, 'Intento de autorización', 'DENIED');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::MANAGER_AUTHORIZATION) throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION', 'Estado inválido.', 409);

            $evaluation = ApplicationEvaluation::where('application_id', $application->id)->first();
            if (!$evaluation || $evaluation->result !== ApplicationEvaluationResult::COMPLIES) throw new BusinessException('APPLICATION_AUTHORIZATION_NOT_ALLOWED', 'Evaluación no cumple.', 403);

            if (ApplicationAuthorization::where('application_id', $application->id)->exists()) {
                throw new BusinessException('APPLICATION_AUTHORIZATION_ALREADY_EXISTS', 'Ya dictaminado.', 409);
            }

            $auth = new ApplicationAuthorization([
                'application_id' => $application->id, 'initial_credit_line_amount' => $initialCreditLine,
                'reason' => $reason, 'authorized_by' => $managerId, 'authorized_at' => now()
            ]);
            $auth->forceFill(['decision' => $decision])->save();

            $newStatus = $decision === ApplicationAuthorizationDecision::APPROVED ? ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION : ApplicationStatus::REJECTED;
            $application->transitionTo($newStatus, $managerId, "Decisión gerencial: " . $decision->value);

            if ($decision === ApplicationAuthorizationDecision::APPROVED) {
                AuditHelper::log('APPLICATION_MANAGER_APPROVED', 'ApplicationAuthorization', $auth->id, $managerId, $application->branch_id, null, ['credit_line' => $initialCreditLine], $reason, 'SUCCESS', $application->lock_version);
            } else {
                AuditHelper::log('APPLICATION_MANAGER_REJECTED', 'ApplicationAuthorization', $auth->id, $managerId, $application->branch_id, null, null, $reason, 'SUCCESS', $application->lock_version);
            }

            return $auth;
        });
    }
}
