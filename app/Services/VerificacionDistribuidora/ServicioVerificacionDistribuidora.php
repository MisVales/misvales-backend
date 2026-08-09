<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\MediaFile;
use App\Models\VerificationVisit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ServicioVerificacionDistribuidora
{
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function consultarAsignadas(string $verifierId): Collection
    {
        $this->acceso->usuarioActivo($verifierId);

        return VerificationVisit::query()
            ->with(['application.branch', 'mediaFiles'])
            ->where('verifier_id', $verifierId)
            ->whereIn('status', [VerificationVisitStatus::ASSIGNED, VerificationVisitStatus::IN_PROGRESS])
            ->latest('assigned_at')
            ->get()
            ->filter(function (VerificationVisit $visit) use ($verifierId): bool {
                try {
                    $this->acceso->exigirVerificador($visit, $verifierId);

                    return true;
                } catch (BusinessException) {
                    return false;
                }
            })
            ->values();
    }

    public function consultarVisita(string $visitId, string $verifierId): VerificationVisit
    {
        $visit = VerificationVisit::query()->with(['application.branch', 'mediaFiles'])->find($visitId);
        if ($visit === null) {
            throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
        }

        $this->acceso->exigirVerificador($visit, $verifierId);

        return $visit;
    }

    public function iniciarVisita(string $visitId, string $verifierId, int $lockVersion): VerificationVisit
    {
        return DB::transaction(function () use ($visitId, $verifierId, $lockVersion): VerificationVisit {
            $visit = VerificationVisit::query()->lockForUpdate()->find($visitId);
            if ($visit === null) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }

            $this->exigirVersion($visit, $lockVersion);
            $this->acceso->exigirVerificador($visit, $verifierId);
            $application = DistributorApplication::query()->lockForUpdate()->findOrFail($visit->application_id);

            if ($visit->status !== VerificationVisitStatus::ASSIGNED
                || $application->status !== ApplicationStatus::VERIFIER_ASSIGNED) {
                throw new BusinessException('INVALID_TRANSITION', 'La visita no puede iniciarse en su estado actual.', 409);
            }

            $application->transitionTo(ApplicationStatus::PHYSICAL_VERIFICATION, $verifierId, 'Visita física iniciada.');
            $visit->forceFill(['status' => VerificationVisitStatus::IN_PROGRESS, 'started_at' => now()])->save();

            AuditHelper::log(
                'VERIFICATION_VISIT_STARTED',
                'VerificationVisit',
                $visit->id,
                $verifierId,
                $application->branch_id,
                previous: ['status' => VerificationVisitStatus::ASSIGNED->value],
                new: ['status' => VerificationVisitStatus::IN_PROGRESS->value],
                version: $visit->lock_version,
            );

            return $visit->load(['application.branch', 'mediaFiles']);
        });
    }

    public function actualizarVisita(string $visitId, string $verifierId, array $data): VerificationVisit
    {
        return DB::transaction(function () use ($visitId, $verifierId, $data): VerificationVisit {
            $visit = VerificationVisit::query()->lockForUpdate()->find($visitId);
            if ($visit === null) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }

            $this->exigirVersion($visit, (int) $data['lock_version']);
            $this->acceso->exigirVerificador($visit, $verifierId);
            $application = DistributorApplication::query()->findOrFail($visit->application_id);

            if ($visit->status !== VerificationVisitStatus::IN_PROGRESS
                || $application->status !== ApplicationStatus::PHYSICAL_VERIFICATION) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_IN_PROGRESS', 'La visita no está en progreso.', 409);
            }

            $previous = [
                'observations' => $visit->observations,
                'differences_payload' => $visit->differences_payload,
            ];

            if (array_key_exists('diferencias', $data)) {
                $this->validarDatosDeclarados($application, $data['diferencias'] ?? []);
                $visit->differences_payload = [
                    'has_differences' => count($data['diferencias'] ?? []) > 0,
                    'items' => $data['diferencias'] ?? [],
                ];
            }

            $visit->observations = $data['observaciones_generales'] ?? $visit->observations;
            $visit->latitude = $data['latitud'] ?? $visit->latitude;
            $visit->longitude = $data['longitud'] ?? $visit->longitude;
            $visit->location_accuracy_meters = $data['precision_metros'] ?? $visit->location_accuracy_meters;
            $visit->save();

            AuditHelper::log(
                'VERIFICATION_VISIT_DOCUMENTED',
                'VerificationVisit',
                $visit->id,
                $verifierId,
                $application->branch_id,
                previous: $previous,
                new: [
                    'observations' => $visit->observations,
                    'differences_payload' => $visit->differences_payload,
                ],
                version: $visit->lock_version,
            );

            return $visit->load(['application.branch', 'mediaFiles']);
        });
    }

    public function finalizarVisita(
        string $visitId,
        string $verifierId,
        string $result,
        ?string $observations,
        int $lockVersion,
    ): VerificationVisit {
        return DB::transaction(function () use ($visitId, $verifierId, $result, $observations, $lockVersion): VerificationVisit {
            $visit = VerificationVisit::query()->lockForUpdate()->find($visitId);
            if ($visit === null) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }

            $this->exigirVersion($visit, $lockVersion);
            $this->acceso->exigirVerificador($visit, $verifierId);
            $application = DistributorApplication::query()->lockForUpdate()->findOrFail($visit->application_id);

            if ($visit->status !== VerificationVisitStatus::IN_PROGRESS
                || $application->status !== ApplicationStatus::PHYSICAL_VERIFICATION) {
                throw new BusinessException('INVALID_TRANSITION', 'La visita no puede finalizarse en su estado actual.', 409);
            }

            if (! MediaFile::query()->where('verification_visit_id', $visit->id)->exists()) {
                throw new BusinessException('VERIFICATION_VISIT_EVIDENCE_REQUIRED', 'Debe registrar al menos una evidencia.', 409);
            }

            if ($visit->differences_payload === null) {
                throw new BusinessException('VERIFICATION_COMPARISON_REQUIRED', 'Debe registrar la comparación del expediente.', 409);
            }

            $visitResult = VerificationVisitResult::from($result);
            $visit->forceFill([
                'status' => VerificationVisitStatus::COMPLETED,
                'result' => $visitResult,
                'observations' => $observations ?? $visit->observations,
                'visited_at' => now(),
                'completed_at' => now(),
            ])->save();

            $nextStatus = match (true) {
                $visitResult === VerificationVisitResult::UNFAVORABLE => ApplicationStatus::TERMINATED_UNFAVORABLE,
                (bool) ($visit->differences_payload['has_differences'] ?? false) => ApplicationStatus::COORDINATOR_CORRECTION,
                default => ApplicationStatus::COORDINATOR_EVALUATION,
            };
            $application->transitionTo($nextStatus, $verifierId, "Resultado físico: {$visitResult->value}");

            AuditHelper::log(
                'VERIFICATION_VISIT_COMPLETED',
                'VerificationVisit',
                $visit->id,
                $verifierId,
                $application->branch_id,
                new: ['result' => $visitResult->value, 'application_status' => $nextStatus->value],
                reason: $visit->observations,
                version: $visit->lock_version,
            );

            return $visit->load(['application.branch', 'mediaFiles']);
        });
    }

    private function validarDatosDeclarados(DistributorApplication $application, array $differences): void
    {
        foreach ($differences as $difference) {
            $path = $difference['seccion'].'.'.$difference['campo'];
            $original = Arr::get($application->original_applicant_data, $path);

            if ($original !== $difference['dato_declarado']) {
                throw new BusinessException(
                    'VERIFICATION_DECLARED_VALUE_MISMATCH',
                    "El valor declarado de {$path} no coincide con el expediente original.",
                    422,
                );
            }
        }
    }

    private function exigirVersion(VerificationVisit $visit, int $lockVersion): void
    {
        if ($visit->lock_version !== $lockVersion) {
            throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
        }
    }
}
