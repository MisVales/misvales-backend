<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\ApplicationCorrection;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ServicioCorreccionSolicitud
{
    public function __construct(private readonly ServicioAccesoVerificacion $acceso) {}

    public function aplicarCorreccion(
        string $applicationId,
        ApplicationCorrectionSection $section,
        string $fieldPath,
        mixed $observedValue,
        mixed $newValue,
        string $reason,
        string $coordinatorId,
        int $lockVersion,
    ): ApplicationCorrection {
        return DB::transaction(function () use (
            $applicationId,
            $section,
            $fieldPath,
            $observedValue,
            $newValue,
            $reason,
            $coordinatorId,
            $lockVersion,
        ): ApplicationCorrection {
            $application = DistributorApplication::query()->lockForUpdate()->find($applicationId);
            if ($application === null) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            $this->exigirVersion($application, $lockVersion);
            $this->acceso->exigirCoordinador($application, $coordinatorId);

            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) {
                throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'El expediente no está en correcciones.', 409);
            }

            $visit = $this->ultimaVisita($application);
            $difference = collect($visit->differences_payload['items'] ?? [])->first(
                fn (array $item): bool => ($item['seccion'] ?? null) === $section->value
                    && ($item['campo'] ?? null) === $fieldPath,
            );

            if ($difference === null) {
                throw new BusinessException('APPLICATION_CORRECTION_DIFFERENCE_NOT_FOUND', 'La diferencia no fue reportada.', 404);
            }

            if (($difference['dato_observado'] ?? null) !== $observedValue) {
                throw new BusinessException('APPLICATION_CORRECTION_OBSERVED_VALUE_MISMATCH', 'El valor observado no coincide.', 422);
            }

            $path = $section->value.'.'.$fieldPath;
            $currentData = $application->applicant_data;
            $previousValue = Arr::get($currentData, $path);
            Arr::set($currentData, $path, $newValue);

            $application->applicant_data = $currentData;
            $application->save();

            $correction = ApplicationCorrection::query()->create([
                'application_id' => $application->id,
                'verification_visit_id' => $visit->id,
                'section' => $section,
                'field_path' => $fieldPath,
                'previous_value_payload' => $previousValue,
                'new_value_payload' => $newValue,
                'reason' => $reason,
                'corrected_by' => $coordinatorId,
                'corrected_at' => now(),
            ]);

            AuditHelper::log(
                'APPLICATION_CORRECTION_APPLIED',
                'ApplicationCorrection',
                $correction->id,
                $coordinatorId,
                $application->branch_id,
                previous: ['section' => $section->value, 'field' => $fieldPath, 'value' => $previousValue],
                new: ['section' => $section->value, 'field' => $fieldPath, 'value' => $newValue],
                reason: $reason,
                version: $application->lock_version,
            );

            return $correction;
        });
    }

    public function finalizarCorrecciones(string $applicationId, string $coordinatorId, int $lockVersion): void
    {
        DB::transaction(function () use ($applicationId, $coordinatorId, $lockVersion): void {
            $application = DistributorApplication::query()->lockForUpdate()->find($applicationId);
            if ($application === null) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            $this->exigirVersion($application, $lockVersion);
            $this->acceso->exigirCoordinador($application, $coordinatorId);

            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) {
                throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'El expediente no está en correcciones.', 409);
            }

            $visit = $this->ultimaVisita($application);
            $pending = collect($visit->differences_payload['items'] ?? [])->contains(function (array $difference) use ($application, $visit): bool {
                return ! ApplicationCorrection::query()
                    ->where('application_id', $application->id)
                    ->where('verification_visit_id', $visit->id)
                    ->where('section', $difference['seccion'])
                    ->where('field_path', $difference['campo'])
                    ->exists();
            });

            if ($pending) {
                throw new BusinessException('APPLICATION_CORRECTIONS_PENDING', 'Existen diferencias sin corrección registrada.', 409);
            }

            $application->transitionTo(ApplicationStatus::COORDINATOR_EVALUATION, $coordinatorId, 'Correcciones concluidas.');
            AuditHelper::log(
                'APPLICATION_CORRECTIONS_COMPLETED',
                'DistributorApplication',
                $application->id,
                $coordinatorId,
                $application->branch_id,
                version: $application->lock_version,
            );
        });
    }

    private function ultimaVisita(DistributorApplication $application): VerificationVisit
    {
        $visit = VerificationVisit::query()
            ->where('application_id', $application->id)
            ->where('status', VerificationVisitStatus::COMPLETED)
            ->latest('completed_at')
            ->first();

        if ($visit === null) {
            throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'No existe una visita finalizada.', 404);
        }

        return $visit;
    }

    private function exigirVersion(DistributorApplication $application, int $lockVersion): void
    {
        if ($application->lock_version !== $lockVersion) {
            throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
        }
    }
}
