<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\ApplicationCorrection;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Illuminate\Support\Facades\DB;

class ServicioCorreccionSolicitud
{
    public function listarDiferencias(string $applicationId, string $coordinatorId): array
    {
        $application = DistributorApplication::where('id', $applicationId)->where('coordinator_id', $coordinatorId)->first();
        if (! $application) {
            throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
        }

        $visit = VerificationVisit::with('mediaFiles')->where('application_id', $applicationId)->orderBy('created_at', 'desc')->first();
        if (! $visit) {
            throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
        }

        $corrections = ApplicationCorrection::where('application_id', $applicationId)->where('verification_visit_id', $visit->id)->get();

        return [
            'application' => $application, 'visit' => $visit,
            'differences' => $visit->differences_payload['items'] ?? [], 'corrections_applied' => $corrections,
        ];
    }

    public function aplicarCorreccion(
        string $applicationId, string $visitId, ApplicationCorrectionSection $section,
        string $fieldPath, $newValuePayload, string $reason, string $coordinatorId, int $lockVersion
    ): ApplicationCorrection {
        return DB::transaction(function () use ($applicationId, $visitId, $section, $fieldPath, $newValuePayload, $reason, $coordinatorId, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }
            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }
            if ($application->coordinator_id !== $coordinatorId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Intento de corrección', 'DENIED');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) {
                throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'No en correcciones.', 409);
            }

            $visit = VerificationVisit::find($visitId);
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }

            $differences = $visit->differences_payload['items'] ?? [];
            $fieldWasReported = collect($differences)->contains(function ($diff) use ($section, $fieldPath) {
                return ($diff['section'] ?? '') === $section->value && ($diff['field'] ?? '') === $fieldPath;
            });
            if (! $fieldWasReported) {
                throw new BusinessException('APPLICATION_CORRECTION_DIFFERENCE_NOT_FOUND', 'Campo no reportado.', 404);
            }

            [$previousValuePayload, $persistedNewValue] = $this->aplicarEnExpedienteCanonico(
                $application->id,
                $section,
                $fieldPath,
                $newValuePayload,
            );
            $application->forceFill(['lock_version' => $application->lock_version + 1])->save();

            $correction = ApplicationCorrection::create([
                'application_id' => $application->id, 'verification_visit_id' => $visit->id,
                'section' => $section, 'field_path' => $fieldPath,
                'previous_value_payload' => json_encode($previousValuePayload),
                'new_value_payload' => json_encode($persistedNewValue),
                'reason' => $reason, 'corrected_by' => $coordinatorId, 'corrected_at' => now(),
            ]);

            AuditHelper::log('APPLICATION_CORRECTION_APPLIED', 'ApplicationCorrection', $correction->id, $coordinatorId, $application->branch_id, null, ['field' => $fieldPath], $reason, 'SUCCESS', $application->lock_version);

            return $correction;
        });
    }

    /** @return array{mixed, mixed} */
    private function aplicarEnExpedienteCanonico(
        string $applicationId,
        ApplicationCorrectionSection $section,
        string $fieldPath,
        mixed $newValue,
    ): array {
        if (! in_array($section, [ApplicationCorrectionSection::PERSONAL_INFO, ApplicationCorrectionSection::PERSONAL_DATA], true)) {
            throw new BusinessException(
                'APPLICATION_CORRECTION_FIELD_MAPPING_UNAVAILABLE',
                'La sección requiere identificar explícitamente el registro histórico; no se adivina el destino.',
                422,
            );
        }

        $datos = DatosPersonalesSolicitud::query()->where('application_id', $applicationId)->lockForUpdate()->first();
        if ($datos === null) {
            throw new BusinessException('APPLICATION_PERSONAL_DATA_NOT_FOUND', 'No existen datos personales canónicos para corregir.', 409);
        }

        $camposDirectos = [
            'first_name', 'first_last_name', 'second_last_name', 'birth_date', 'birth_place',
            'birth_state', 'birth_city', 'email', 'phone_number', 'official_id_type',
        ];
        if (in_array($fieldPath, $camposDirectos, true)) {
            $anterior = $datos->getAttribute($fieldPath);
            $datos->setAttribute($fieldPath, $newValue);
            $datos->save();

            return [$anterior, $newValue];
        }

        $protector = app(ProtectorDatosSolicitud::class);
        $columnas = match ($fieldPath) {
            'curp' => ['curp_ciphertext', 'curp_hmac', 'cifrarCurp', 'generarHmacCurp'],
            'rfc' => ['rfc_ciphertext', 'rfc_hmac', 'cifrarRfc', 'generarHmacRfc'],
            'official_id_number' => ['official_id_number_ciphertext', 'official_id_number_hmac', 'cifrarIdentificacion', 'generarHmacIdentificacion'],
            default => null,
        };
        if ($columnas === null || ! is_string($newValue)) {
            throw new BusinessException('APPLICATION_CORRECTION_FIELD_INVALID', 'Campo de corrección no permitido.', 422);
        }

        [$ciphertext, $hmac, $metodoCifrado, $metodoHmac] = $columnas;
        $anterior = $datos->getAttribute($ciphertext);
        $nuevoCifrado = $protector->{$metodoCifrado}($newValue);
        $datos->forceFill([
            $ciphertext => $nuevoCifrado,
            $hmac => $protector->{$metodoHmac}($newValue),
        ])->save();

        return [$anterior, $nuevoCifrado];
    }

    public function finalizarCorrecciones(string $applicationId, string $coordinatorId, int $lockVersion): void
    {
        DB::transaction(function () use ($applicationId, $coordinatorId, $lockVersion) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }
            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }
            if ($application->coordinator_id !== $coordinatorId) {
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) {
                throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'No en correcciones.', 409);
            }

            $visit = VerificationVisit::where('application_id', $applicationId)->orderBy('created_at', 'desc')->first();
            $differences = $visit->differences_payload['items'] ?? [];
            $correctionCount = ApplicationCorrection::where('application_id', $applicationId)->where('verification_visit_id', $visit->id)->count();
            if ($correctionCount < count($differences)) {
                throw new BusinessException('APPLICATION_CORRECTIONS_PENDING', 'Faltan diferencias por corregir.', 409);
            }

            $application->transitionTo(ApplicationStatus::COORDINATOR_EVALUATION, $coordinatorId, 'Correcciones terminadas');

            AuditHelper::log('APPLICATION_CORRECTIONS_COMPLETED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Etapa terminada', 'SUCCESS', $application->lock_version);
        });
    }
}
