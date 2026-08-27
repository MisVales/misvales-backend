<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ServicioRevisionCoordinador
{
    public function __construct(private readonly PoliticaHorarioVerificacion $schedulePolicy) {}

    private const VISIT_DURATION_MINUTES = 30;

    private const ARRIVAL_BUFFER_BEFORE_MINUTES = 15;

    private const TRAVEL_BUFFER_AFTER_MINUTES = 30;

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

    public function consultarAgendaVerificador(string $applicationId, string $coordinatorId, string $verifierId, string $from, string $to): array
    {
        $application = DistributorApplication::find($applicationId);
        if (! $application) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }
        if (! $this->puedeRevisarSolicitud($application, $coordinatorId)) {
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
        }

        $isVerifierInApplicationBranch = User::query()
            ->whereKey($verifierId)
            ->where('state', 'ACTIVE')
            ->whereHas('roleScopes', fn ($scopes) => $scopes
                ->where('branch_id', $application->branch_id)
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($roles) => $roles->where('code', 'verifier')))
            ->exists();
        if (! $isVerifierInApplicationBranch) {
            throw new BusinessException('VERIFIER_BRANCH_MISMATCH', 'El verificador no está disponible para esta sucursal.', 403);
        }

        return VerificationVisit::query()
            ->select(['id', 'application_id', 'scheduled_for', 'status'])
            ->with('application:id,application_number')
            ->where('verifier_id', $verifierId)
            ->whereIn('status', [VerificationVisitStatus::ASSIGNED, VerificationVisitStatus::IN_PROGRESS])
            ->whereBetween('scheduled_for', [CarbonImmutable::parse($from), CarbonImmutable::parse($to)])
            ->orderBy('scheduled_for')
            ->get()
            ->map(fn (VerificationVisit $visit): array => [
                'id' => $visit->id,
                'scheduled_for' => $visit->scheduled_for?->toIso8601String(),
                'status' => $visit->status->value,
                'application_number' => $visit->application?->application_number,
                'reserved_from' => $visit->scheduled_for?->copy()->subMinutes(self::ARRIVAL_BUFFER_BEFORE_MINUTES)->toIso8601String(),
                'reserved_until' => $visit->scheduled_for?->copy()->addMinutes(self::VISIT_DURATION_MINUTES + self::TRAVEL_BUFFER_AFTER_MINUTES)->toIso8601String(),
            ])
            ->all();
    }

    public function asignarVerificador(string $applicationId, string $coordinatorId, string $verifierId, string $scheduledFor, int $lockVersion): VerificationVisit
    {
        return DB::transaction(function () use ($applicationId, $coordinatorId, $verifierId, $scheduledFor, $lockVersion) {
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

            $verifier = User::query()->lockForUpdate()->find($verifierId);
            if (! $verifier) {
                throw new BusinessException('VERIFIER_NOT_FOUND', 'Verificador no encontrado.', 404);
            }
            if (! $verifier->is_active) {
                throw new BusinessException('VERIFIER_INACTIVE', 'Verificador inactivo.', 409);
            }
            if ($verifier->id === $application->created_by) {
                throw new BusinessException('SEGREGATION_OF_DUTIES_VIOLATION', 'Quien capturó la solicitud no puede verificarla.', 403);
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

            $scheduled = CarbonImmutable::parse($scheduledFor)
                ->setTimezone(config('app.timezone'));
            $this->schedulePolicy->validar($scheduled);
            $separationMinutes = self::ARRIVAL_BUFFER_BEFORE_MINUTES
                + self::VISIT_DURATION_MINUTES
                + self::TRAVEL_BUFFER_AFTER_MINUTES;
            $hasScheduleConflict = VerificationVisit::query()
                ->where('verifier_id', $verifierId)
                ->whereIn('status', [VerificationVisitStatus::ASSIGNED, VerificationVisitStatus::IN_PROGRESS])
                ->where('scheduled_for', '>', $scheduled->subMinutes($separationMinutes))
                ->where('scheduled_for', '<', $scheduled->addMinutes($separationMinutes))
                ->exists();
            if ($hasScheduleConflict) {
                throw new BusinessException(
                    'VERIFIER_SCHEDULE_CONFLICT',
                    'Ese horario se cruza con otra visita. Deja al menos 75 minutos entre inicios para validar y trasladarse.',
                    409,
                );
            }

            $application->transitionTo(ApplicationStatus::VERIFIER_ASSIGNED, $coordinatorId, 'Verificador asignado');

            $visit = new VerificationVisit([
                'application_id' => $application->id,
                'verifier_id' => $verifierId,
                'assigned_by' => $coordinatorId,
                'assigned_at' => now(),
                'scheduled_for' => $scheduled,
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
                            ->whereHas('role', fn ($roles) => $roles->where('code', 'general_manager'));
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
