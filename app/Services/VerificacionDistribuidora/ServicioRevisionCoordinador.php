<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Support\Facades\DB;

class ServicioRevisionCoordinador
{
    public function listarVerificadoresDisponibles(string $applicationId, string $coordinatorId): array
    {
        $application = DistributorApplication::find($applicationId);
        if (! $application) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }
        if (! $this->puedeRevisarSolicitud($application, $coordinatorId)) {
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
        }

        return User::query()
            ->select(['users.id', 'users.name', 'users.state'])
            ->join('user_role_scopes', 'user_role_scopes.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_role_scopes.role_id')
            ->where('roles.code', 'verifier')
            ->where('user_role_scopes.branch_id', $application->branch_id)
            ->where('user_role_scopes.status', 'ACTIVE')
            ->whereNull('user_role_scopes.revoked_at')
            ->where('users.state', 'ACTIVE')
            ->orderBy('users.name')
            ->get()
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'branch_id' => $application->branch_id, 'state' => is_object($user->state) ? $user->state->value : $user->state])
            ->all();
    }

    public function devolverACaptura(string $applicationId, string $coordinatorId, string $reason, array $pendingSections, int $lockVersion): void
    {
        DB::transaction(function () use ($applicationId, $coordinatorId, $reason, $pendingSections, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            if (! $this->puedeRevisarSolicitud($application, $coordinatorId)) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'No autorizado para devolver');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_REVIEW) {
                if ($application->status === ApplicationStatus::TERMINATED_UNFAVORABLE) {
                    throw new BusinessException('DISTRIBUTOR_APPLICATION_ALREADY_TERMINATED', 'La solicitud ya está terminada.', 409);
                }
                throw new BusinessException('DISTRIBUTOR_APPLICATION_INVALID_STATE', 'La solicitud no está en revisión.', 409);
            }

            $application->pending_sections = $pendingSections;
            $application->transitionTo(ApplicationStatus::DRAFT, $coordinatorId, 'Devuelto a captura: '.$reason);

            AuditHelper::log('DISTRIBUTOR_APPLICATION_RETURNED_TO_DRAFT', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, ['pending_sections' => $pendingSections], $reason, 'SUCCESS', $application->lock_version);
        });
    }

    public function asignarVerificador(string $applicationId, string $coordinatorId, string $verifierId, int $lockVersion): VerificationVisit
    {
        return DB::transaction(function () use ($applicationId, $coordinatorId, $verifierId, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            if (! $this->puedeRevisarSolicitud($application, $coordinatorId)) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'No autorizado para asignar');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_REVIEW) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_READY_FOR_VERIFICATION', 'La solicitud no está lista para verificación.', 409);
            }

            $verifier = User::find($verifierId);
            if (! $verifier) {
                throw new BusinessException('VERIFIER_NOT_FOUND', 'Verificador no encontrado.', 404);
            }
            if (! $verifier->is_active) {
                throw new BusinessException('VERIFIER_INACTIVE', 'Verificador inactivo.', 409);
            }
            if (! method_exists($verifier, 'hasRole') || ! $verifier->hasRole('verifier')) {
                throw new BusinessException('VERIFIER_ROLE_INVALID', 'Rol de verificador inválido.', 403);
            }
            $isVerifierInApplicationBranch = $verifier->roleScopes()
                ->where('branch_id', $application->branch_id)
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($role) => $role->where('code', 'verifier'))
                ->exists();
            if (! $isVerifierInApplicationBranch) {
                throw new BusinessException('VERIFIER_BRANCH_MISMATCH', 'Sucursal incorrecta.', 403);
            }

            if (VerificationVisit::where('application_id', $application->id)->whereIn('status', [VerificationVisitStatus::ASSIGNED, VerificationVisitStatus::IN_PROGRESS])->exists()) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_STARTED', 'Ya existe una visita activa.', 409);
            }

            $application->transitionTo(ApplicationStatus::VERIFIER_ASSIGNED, $coordinatorId, 'Verificador asignado');

            $visit = new VerificationVisit([
                'application_id' => $application->id,
                'verifier_id' => $verifierId,
                'assigned_by' => $coordinatorId,
                'assigned_at' => now(),
            ]);
            $visit->forceFill(['status' => VerificationVisitStatus::ASSIGNED])->save();

            AuditHelper::log('VERIFICATION_VISIT_ASSIGNED', 'VerificationVisit', $visit->id, $coordinatorId, $application->branch_id, null, ['verifier_id' => $verifierId], null, 'SUCCESS', $visit->lock_version);

            return $visit;
        });
    }

    private function puedeRevisarSolicitud(DistributorApplication $application, string $actorId): bool
    {
        if ($application->coordinator_id === $actorId) {
            return true;
        }

        return User::query()
            ->whereKey($actorId)
            ->whereHas('roleScopes', function ($scopes) use ($application): void {
                $scopes->where(function ($authorized) use ($application): void {
                    $authorized->where(function ($global) {
                        $global->where('status', 'ACTIVE')
                            ->whereNull('revoked_at')
                            ->where('scope_type', 'GLOBAL')
                            ->whereHas('role', fn ($roles) => $roles->whereIn('code', ['general_manager', 'admin']));
                    })->orWhere(function ($branchManager) use ($application): void {
                        $branchManager->where('status', 'ACTIVE')
                            ->whereNull('revoked_at')
                            ->where('branch_id', $application->branch_id)
                            ->whereHas('role', fn ($roles) => $roles->where('code', 'branch_manager'));
                    });
                });
            })
            ->exists();
    }
}
