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
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function asignarVerificador(
        string $applicationId,
        string $coordinatorId,
        string $verifierId,
        int $lockVersion,
    ): VerificationVisit {
        return DB::transaction(function () use ($applicationId, $coordinatorId, $verifierId, $lockVersion): VerificationVisit {
            $application = DistributorApplication::query()->lockForUpdate()->find($applicationId);

            if ($application === null) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            $this->exigirVersion($application, $lockVersion);
            $this->acceso->exigirCoordinador($application, $coordinatorId);

            if ($application->submitted_by === null) {
                throw new BusinessException(
                    'APPLICATION_SUBMITTER_REQUIRED',
                    'El expediente no identifica al usuario que realizÃ³ la captura.',
                    409,
                );
            }

            if ($application->status !== ApplicationStatus::COORDINATOR_REVIEW) {
                throw new BusinessException(
                    'DISTRIBUTOR_APPLICATION_NOT_READY_FOR_VERIFICATION',
                    'La solicitud no está lista para asignar verificador.',
                    409,
                );
            }

            $verifier = User::query()->find($verifierId);
            if ($verifier === null) {
                throw new BusinessException('VERIFIER_NOT_FOUND', 'Verificador no encontrado.', 404);
            }

            if ($verifier->state !== 'ACTIVE') {
                throw new BusinessException('VERIFIER_INACTIVE', 'El verificador no está activo.', 409);
            }

            if (! $this->acceso->tieneRolEnSucursal($verifier, 'verifier', $application->branch_id)) {
                throw new BusinessException('VERIFIER_BRANCH_MISMATCH', 'El verificador no pertenece a la sucursal.', 403);
            }

            $this->acceso->exigirSeparacion(
                $application,
                $verifierId,
                ['submitted_by', 'coordinator_id'],
                'El capturista o coordinador del expediente no puede ser su verificador.',
            );

            if (VerificationVisit::query()
                ->where('application_id', $application->id)
                ->whereIn('status', [VerificationVisitStatus::ASSIGNED, VerificationVisitStatus::IN_PROGRESS])
                ->exists()) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_EXISTS', 'Ya existe una visita abierta.', 409);
            }

            $application->verifier_id = $verifierId;
            $application->transitionTo(ApplicationStatus::VERIFIER_ASSIGNED, $coordinatorId, 'Verificador asignado.');

            $visit = new VerificationVisit([
                'application_id' => $application->id,
                'verifier_id' => $verifierId,
                'assigned_by' => $coordinatorId,
                'assigned_at' => now(),
            ]);
            $visit->forceFill(['status' => VerificationVisitStatus::ASSIGNED])->save();

            AuditHelper::log(
                'VERIFICATION_VISIT_ASSIGNED',
                'VerificationVisit',
                $visit->id,
                $coordinatorId,
                $application->branch_id,
                new: ['verifier_id' => $verifierId],
                version: $application->lock_version,
            );

            return $visit;
        });
    }

    private function exigirVersion(DistributorApplication $application, int $lockVersion): void
    {
        if ($application->lock_version !== $lockVersion) {
            throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
        }
    }
}
