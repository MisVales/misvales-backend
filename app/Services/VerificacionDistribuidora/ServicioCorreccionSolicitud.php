<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use App\Enums\ApplicationStatus;
use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\ApplicationCorrection;
use App\Models\CreditoComercialSolicitud;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DistributorApplication;
use App\Models\DomicilioSolicitud;
use App\Models\EmpleoSolicitud;
use App\Models\FamiliarSolicitud;
use App\Models\PatrimonioSolicitud;
use App\Models\VehiculoSolicitud;
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
        string $fieldPath, string $coordinatorId, int $lockVersion,
        ?string $recordId, int $differenceIndex
    ): ApplicationCorrection {
        return DB::transaction(function () use ($applicationId, $visitId, $section, $fieldPath, $coordinatorId, $lockVersion, $recordId, $differenceIndex) {
            $application = DistributorApplication::lockForUpdate()->find($applicationId);
            if (! $application) {
                throw new BusinessException('DISTRIBUTOR_APPLICATION_NOT_FOUND', 'Solicitud no encontrada.', 404);
            }
            if ($application->coordinator_id !== $coordinatorId) {
                AuditHelper::log('VERIFICATION_ACCESS_DENIED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Intento de corrección', 'DENIED');
                throw new BusinessException('AUTH_SCOPE_DENIED', 'No autorizado.', 403);
            }
            if ($application->status !== ApplicationStatus::COORDINATOR_CORRECTION) {
                throw new BusinessException('APPLICATION_CORRECTION_NOT_ALLOWED', 'No en correcciones.', 409);
            }

            $visit = VerificationVisit::query()->whereKey($visitId)->where('application_id', $application->id)->first();
            if (! $visit) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }

            $differences = $visit->differences_payload['items'] ?? [];
            $difference = $differences[$differenceIndex] ?? null;
            if (! is_array($difference)
                || ($difference['section'] ?? '') !== $section->value
                || ($difference['field'] ?? '') !== $fieldPath
                || (isset($difference['record_id']) && $difference['record_id'] !== $recordId)) {
                throw new BusinessException('APPLICATION_CORRECTION_DIFFERENCE_NOT_FOUND', 'Campo no reportado.', 404);
            }
            $existingCorrection = ApplicationCorrection::query()
                ->where('verification_visit_id', $visit->id)
                ->where('difference_index', $differenceIndex)
                ->first();
            if ($existingCorrection !== null) {
                return $existingCorrection;
            }
            if ($application->lock_version !== $lockVersion) {
                throw new BusinessException('RESOURCE_VERSION_CONFLICT', 'Conflicto de concurrencia.', 409);
            }
            $newValuePayload = $difference['observed_value'] ?? null;
            $reason = (string) ($difference['description'] ?? 'Corrección indicada por el verificador.');
            if ($newValuePayload === null || $newValuePayload === '') {
                throw new BusinessException('APPLICATION_CORRECTION_VALUE_MISSING', 'El verificador no capturó el valor corregido para esta diferencia.', 422);
            }

            [$previousValuePayload, $persistedNewValue] = $this->aplicarEnExpedienteCanonico(
                $application->id,
                $section,
                $fieldPath,
                $newValuePayload,
                $recordId,
            );
            $application->forceFill(['lock_version' => $application->lock_version + 1])->save();

            $correction = ApplicationCorrection::create([
                'application_id' => $application->id, 'verification_visit_id' => $visit->id,
                'section' => $section, 'field_path' => $fieldPath,
                'target_record_id' => $recordId, 'difference_index' => $differenceIndex,
                'previous_value_payload' => ['value' => $previousValuePayload],
                'new_value_payload' => ['value' => $persistedNewValue],
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
        ?string $recordId,
    ): array {
        if (! in_array($section, [ApplicationCorrectionSection::PERSONAL_INFO, ApplicationCorrectionSection::PERSONAL_DATA], true)) {
            return $this->aplicarEnRegistro($applicationId, $section, $fieldPath, $newValue, $recordId);
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
        if ($fieldPath === 'has_identification_evidence') {
            return [$datos->has_identification_evidence ?? null, $newValue];
        }
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

    /** @return array{mixed, mixed} */
    private function aplicarEnRegistro(string $applicationId, ApplicationCorrectionSection $section, string $fieldPath, mixed $newValue, ?string $recordId): array
    {
        if ($recordId === null) {
            throw new BusinessException('APPLICATION_CORRECTION_RECORD_REQUIRED', 'Selecciona el registro exacto que deseas corregir.', 422);
        }

        [$model, $allowedFields] = match ($section) {
            ApplicationCorrectionSection::FAMILY_MEMBERS => [FamiliarSolicitud::class, ['relationship', 'first_name', 'first_last_name', 'second_last_name', 'birth_date', 'school_name']],
            ApplicationCorrectionSection::RESIDENCES => [DomicilioSolicitud::class, ['street', 'exterior_number', 'interior_number', 'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'country', 'housing_tenure', 'financing_status', 'width_meters', 'length_meters', 'built_area_square_meters']],
            ApplicationCorrectionSection::VEHICLES => [VehiculoSolicitud::class, ['vehicle_type', 'brand', 'model', 'model_year', 'ownership_status']],
            ApplicationCorrectionSection::ASSETS_LIABILITIES => [PatrimonioSolicitud::class, ['entry_type', 'name', 'amount', 'outstanding_balance', 'monthly_payment', 'is_active']],
            ApplicationCorrectionSection::EMPLOYMENTS => [EmpleoSolicitud::class, ['employer_name', 'job_title', 'started_at', 'ended_at', 'is_current']],
            ApplicationCorrectionSection::COMMERCIAL_CREDITS => [CreditoComercialSolicitud::class, ['company_name', 'credit_limit', 'is_current', 'proof_reference']],
            default => throw new BusinessException('APPLICATION_CORRECTION_FIELD_INVALID', 'Campo de corrección no permitido.', 422),
        };
        if (! in_array($fieldPath, $allowedFields, true)) {
            throw new BusinessException('APPLICATION_CORRECTION_FIELD_INVALID', 'Campo de corrección no permitido.', 422);
        }

        $record = $model::query()->whereKey($recordId)->where('application_id', $applicationId)->lockForUpdate()->first();
        if ($record === null) {
            throw new BusinessException('APPLICATION_CORRECTION_RECORD_NOT_FOUND', 'El registro seleccionado no pertenece a esta solicitud.', 404);
        }
        $previous = $record->getAttribute($fieldPath);
        if ($fieldPath === 'proof_reference') {
            return [$previous, $newValue];
        }
        $record->setAttribute($fieldPath, $newValue);
        $record->save();

        return [$previous, $record->getAttribute($fieldPath)];
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
            $correctionCount = ApplicationCorrection::where('application_id', $applicationId)
                ->where('verification_visit_id', $visit->id)
                ->whereNotNull('difference_index')
                ->distinct()
                ->count('difference_index');
            if ($correctionCount < count($differences)) {
                throw new BusinessException('APPLICATION_CORRECTIONS_PENDING', 'Faltan diferencias por corregir.', 409);
            }

            $application->transitionTo(ApplicationStatus::COORDINATOR_EVALUATION, $coordinatorId, 'Correcciones terminadas');

            AuditHelper::log('APPLICATION_CORRECTIONS_COMPLETED', 'DistributorApplication', $application->id, $coordinatorId, $application->branch_id, null, null, 'Etapa terminada', 'SUCCESS', $application->lock_version);
        });
    }
}
