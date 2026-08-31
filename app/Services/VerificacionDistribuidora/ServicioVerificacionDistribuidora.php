<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\ApiException;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\MediaFileBinding;
use App\Models\SolicitudDistribuidora;
use App\Models\VerificationVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ServicioVerificacionDistribuidora
{
    public function __construct(private readonly PoliticaHorarioVerificacion $schedulePolicy) {}

    public function consultarAsignadas(string $verifierId): Collection
    {
        $this->asegurarVerificador($verifierId);

        return VerificationVisit::with([
            'application.datosPersonales',
            'application.branch:id,name',
            'application.coordinator:id,name',
        ])
            ->where('verifier_id', $verifierId)
            ->orderByDesc('scheduled_for')
            ->get();
    }

    public function consultarVisita(string $visitId, string $verifierId): VerificationVisit
    {
        $this->asegurarVerificador($verifierId);

        $visit = VerificationVisit::with(['application', 'mediaFiles'])
            ->where('id', $visitId)
            ->where('verifier_id', $verifierId)
            ->first();
        if (! $visit) {
            throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
        }

        $application = SolicitudDistribuidora::with([
            'sucursal', 'coordinador', 'datosPersonales', 'familiares', 'domicilios',
            'vehiculos', 'patrimonio', 'empleos', 'creditosComerciales',
        ])->findOrFail($visit->application_id);
        $visit->setRelation('application', $application);
        $ownerIds = [
            'application_vehicle' => $application->vehiculos->modelKeys(),
            'application_asset_liability' => $application->patrimonio->modelKeys(),
            'application_commercial_credit' => $application->creditosComerciales->modelKeys(),
        ];
        $bindings = MediaFileBinding::query()
            ->with('mediaFile')
            ->where(function ($query) use ($application, $ownerIds): void {
                $query->where(function ($query) use ($application): void {
                    $query->where('owner_type', 'distributor_application')
                        ->where('owner_id', $application->id)
                        ->whereIn('purpose', ['IDENTIFICATION', 'ADDRESS_PROOF', 'VEHICLE_EVIDENCE', 'ASSET_EVIDENCE', 'COMMERCIAL_EVIDENCE']);
                });
                foreach ($ownerIds as $ownerType => $ids) {
                    if ($ids !== []) {
                        $query->orWhere(function ($query) use ($ownerType, $ids): void {
                            $query->where('owner_type', $ownerType)->whereIn('owner_id', $ids);
                        });
                    }
                }
            })
            ->get();
        $declaredMedia = $bindings->pluck('mediaFile')->filter()->unique('id')->values();
        $visit->setRelation('declaredMediaFiles', $declaredMedia);

        return $visit;
    }

    public function iniciarVisita(string $visitId, string $verifierId, int $lockVersion): VerificationVisit
    {
        $this->asegurarVerificador($verifierId);

        return DB::transaction(function () use ($visitId, $verifierId, $lockVersion) {
            $visit = VerificationVisit::query()
                ->whereKey($visitId)
                ->where('verifier_id', $verifierId)
                ->lockForUpdate()
                ->first();
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }
            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $application = DistributorApplication::lockForUpdate()->find($visit->application_id);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, null, 'Intento de inicio no autorizado');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No asignado a este verificador.', 403);
            }
            if ($visit->status === VerificationVisitStatus::IN_PROGRESS) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_STARTED', 'La visita ya está en progreso.', 409);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'La visita ya está completada.', 409);
            }
            if ($visit->status !== VerificationVisitStatus::ASSIGNED) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_INVALID_STATE', 'La visita no está asignada.', 409);
            }

            $timezone = 'America/Monterrey';
            $now = CarbonImmutable::now($timezone);
            $this->schedulePolicy->validarHoraDeInicio($now);
            $scheduled = $visit->scheduled_for?->toImmutable()->setTimezone($timezone);
            if ($scheduled === null) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_SCHEDULED', 'La visita no tiene fecha y hora programadas.', 409);
            }
            if (! $now->isSameDay($scheduled) || $now->lessThan($scheduled->subMinutes(15))) {
                throw new BusinessException(
                    'VERIFICATION_VISIT_OUTSIDE_SCHEDULE',
                    'La visita puede iniciarse desde 15 minutos antes de la hora programada o más tarde durante ese mismo día.',
                    409
                );
            }

            $application->transitionTo(ApplicationStatus::PHYSICAL_VERIFICATION, $verifierId, 'Inicio de visita');

            $visit->forceFill([
                'status' => VerificationVisitStatus::IN_PROGRESS,
                'started_at' => now(),
            ])->save();

            AuditHelper::log('VERIFICATION_VISIT_STARTED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, ['status' => 'IN_PROGRESS'], null, 'SUCCESS', $visit->lock_version);

            return $visit;
        });
    }

    public function actualizarVisita(string $visitId, string $verifierId, ?float $lat, ?float $lng, ?float $accuracy, int $lockVersion): void
    {
        $this->asegurarVerificador($verifierId);

        DB::transaction(function () use ($visitId, $verifierId, $lat, $lng, $accuracy, $lockVersion) {
            $visit = VerificationVisit::query()
                ->whereKey($visitId)
                ->where('verifier_id', $verifierId)
                ->lockForUpdate()
                ->first();
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }
            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }
            if ($visit->verifier_id !== $verifierId) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No asignado a este verificador.', 403);
            }
            if ($visit->status === VerificationVisitStatus::ASSIGNED) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'Visita no iniciada.', 409);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita completada.', 409);
            }

            $visit->forceFill([
                'latitude' => $lat === null ? null : (string) $lat,
                'longitude' => $lng === null ? null : (string) $lng,
                'location_accuracy_meters' => $accuracy === null ? null : (string) $accuracy,
            ])->save();
        });
    }

    public function registrarDiferencias(string $visitId, string $verifierId, array $differencesPayload, int $lockVersion): void
    {
        $this->asegurarVerificador($verifierId);

        DB::transaction(function () use ($visitId, $verifierId, $differencesPayload, $lockVersion) {
            $visit = VerificationVisit::query()
                ->whereKey($visitId)
                ->where('verifier_id', $verifierId)
                ->lockForUpdate()
                ->first();
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }
            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }
            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'VerificationVisit', $visit->id, $verifierId, null, null, null, 'Intento de registro de diferencias no autorizado');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::ASSIGNED) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'Visita no iniciada.', 409);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita completada.', 409);
            }

            foreach (($differencesPayload['items'] ?? []) as $index => $item) {
                $message = ValidadorValorVerificacion::mensaje(
                    (string) ($item['field'] ?? ''),
                    $item['observed_value'] ?? null,
                    true,
                );
                if ($message !== null) {
                    throw new ApiException(
                        'VERIFICATION_DIFFERENCE_VALUE_INVALID',
                        $message,
                        422,
                        ["differences_payload.items.{$index}.observed_value" => [$message]],
                    );
                }
            }

            $visit->forceFill(['differences_payload' => $differencesPayload])->save();

            $previousValues = [];
            $newValues = [];
            $changes = [];
            $items = $differencesPayload['items'] ?? [];
            $itemsCount = count($items);

            foreach ($items as $item) {
                $section = $item['section'] ?? 'seccion';
                $field = $item['field'] ?? 'campo';
                $recordId = $item['record_id'] ?? null;
                $recordLabel = $item['record_label'] ?? null;
                $before = $item['declared_value'] ?? null;
                $after = $item['observed_value'] ?? null;

                $changes[] = [
                    'field' => $field,
                    'section' => $section,
                    'record_id' => $recordId,
                    'record_label' => $recordLabel,
                    'before' => $before,
                    'after' => $after,
                ];
            }

            if ($itemsCount === 0) {
                $newValues['has_differences'] = $differencesPayload['has_differences'] ?? false;
            }

            if ($changes !== []) {
                $previousValues['changes'] = $changes;
                $newValues['changes'] = $changes;
            }

            $reason = $itemsCount > 0
                ? "Se registraron {$itemsCount} diferencia(s) observadas durante la visita de verificación."
                : "Sin diferencias reportadas en la visita de verificación.";

            AuditHelper::log(
                'VERIFICATION_DIFFERENCE_RECORDED',
                'VerificationVisit',
                $visit->id,
                $verifierId,
                null,
                $previousValues ?: null,
                $newValues,
                $reason,
                'SUCCESS',
                $visit->lock_version
            );
        });
    }

    public function finalizarVisita(
        string $visitId,
        string $verifierId,
        string $result,
        ?string $observations,
        int $lockVersion
    ): void {
        $this->asegurarVerificador($verifierId);

        DB::transaction(function () use ($visitId, $verifierId, $result, $observations, $lockVersion) {
            $visit = VerificationVisit::query()
                ->whereKey($visitId)
                ->where('verifier_id', $verifierId)
                ->lockForUpdate()
                ->first();
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }
            if ($visit->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }

            $application = DistributorApplication::lockForUpdate()->find($visit->application_id);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }

            if ($visit->verifier_id !== $verifierId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, null, 'Intento de finalización no autorizado');
                throw new BusinessException('VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER', 'No autorizado.', 403);
            }
            if ($visit->status === VerificationVisitStatus::ASSIGNED) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_STARTED', 'Visita no iniciada.', 409);
            }
            if ($visit->status === VerificationVisitStatus::COMPLETED) {
                throw new BusinessException('VERIFICATION_VISIT_ALREADY_COMPLETED', 'Visita ya completada.', 409);
            }

            $evidenceCount = MediaFileBinding::where('owner_type', 'verification_visit')->where('owner_id', $visit->id)->count();
            if ($evidenceCount === 0) {
                throw new BusinessException('VERIFICATION_VISIT_EVIDENCE_REQUIRED', 'No se puede finalizar la visita sin evidencias.', 409);
            }

            $resEnum = VerificationVisitResult::tryFrom($result);
            if (! $resEnum) {
                throw new BusinessException('VERIFICATION_VISIT_RESULT_INVALID', 'Resultado inválido.', 422);
            }

            if ($resEnum === VerificationVisitResult::UNFAVORABLE && empty($observations)) {
                throw new BusinessException('VERIFICATION_VISIT_RESULT_INVALID', 'Se requieren observaciones para visitas desfavorables.', 422);
            }

            $visit->forceFill([
                'status' => VerificationVisitStatus::COMPLETED,
                'completed_at' => now(),
                'visited_at' => now(),
                'result' => $resEnum,
                'observations' => $observations,
            ])->save();

            $newValues = [
                'result' => $resEnum->value,
                'observations' => $observations,
                'completed_at' => now()->toIso8601String(),
            ];
            $visitReason = $observations ?? "Visita de verificación completada: {$resEnum->value}";

            AuditHelper::log('VERIFICATION_VISIT_COMPLETED', 'VerificationVisit', $visit->id, $verifierId, $application->branch_id, null, $newValues, $visitReason, 'SUCCESS', $visit->lock_version);

            $appStatus = $resEnum === VerificationVisitResult::FAVORABLE
                ? ApplicationStatus::COORDINATOR_EVALUATION
                : ApplicationStatus::TERMINATED_UNFAVORABLE;

            $differencesPayload = $visit->differences_payload ?? [];
            if ($resEnum === VerificationVisitResult::FAVORABLE && ! empty($differencesPayload) && ($differencesPayload['has_differences'] ?? false)) {
                $appStatus = ApplicationStatus::COORDINATOR_CORRECTION;
            }

            $application->transitionTo($appStatus, $verifierId, 'Visita completada: '.$resEnum->value);

            if ($appStatus === ApplicationStatus::TERMINATED_UNFAVORABLE) {
                AuditHelper::log('APPLICATION_TERMINATED_UNFAVORABLE', 'DistributorApplication', $application->id, $verifierId, $application->branch_id, null, null, $observations, 'SUCCESS', $application->lock_version);
            }
        });
    }

    private function asegurarVerificador(string $userId): void
    {
        $user = \App\Models\User::query()->find($userId);
        if ($user === null || ! $user->hasRole('verifier')) {
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No tienes permiso para operar visitas de verificación.', 403);
        }
    }
}
