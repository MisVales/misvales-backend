<?php

namespace App\Services\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use App\Enums\ApplicationStatus;
use App\Exceptions\ApiException;
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
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ServicioCorreccionSolicitud
{
    public function listarDiferencias(string $applicationId, string $coordinatorId): array
    {
        $this->asegurarCoordinador($coordinatorId);

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
        ?string $recordId, int $differenceIndex,
        mixed $customNewValue = null,
        ?string $customReason = null
    ): ApplicationCorrection {
        $this->asegurarCoordinador($coordinatorId);
        $fieldPath = $this->campoCanonico($fieldPath);

        return DB::transaction(function () use ($applicationId, $visitId, $section, $fieldPath, $coordinatorId, $lockVersion, $recordId, $differenceIndex, $customNewValue, $customReason) {
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
                || $this->campoCanonico((string) ($difference['field'] ?? '')) !== $fieldPath
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
            $newValuePayload = $customNewValue ?? $difference['observed_value'] ?? $difference['dato_observado'] ?? null;
            $reason = (string) ($customReason ?? $difference['description'] ?? 'Corrección indicada por el verificador.');
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

            $detalleCambio = [[
                'field' => $fieldPath,
                'before' => $this->valorAuditable($fieldPath, $previousValuePayload),
                'after' => $this->valorAuditable($fieldPath, $persistedNewValue),
            ]];
            AuditHelper::log(
                'APPLICATION_CORRECTION_APPLIED',
                'ApplicationCorrection',
                $correction->id,
                $coordinatorId,
                $application->branch_id,
                ['field' => $fieldPath, 'section' => $section->value, 'record_id' => $recordId, 'changes' => $detalleCambio],
                ['field' => $fieldPath, 'section' => $section->value, 'record_id' => $recordId, 'changes' => $detalleCambio],
                $reason,
                'SUCCESS',
                $application->lock_version,
            );

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

        $newValue = $this->normalizarValorDatosPersonales($fieldPath, $newValue);

        $camposDirectos = [
            'first_name', 'first_last_name', 'second_last_name', 'birth_date', 'birth_place',
            'birth_state', 'birth_city', 'email', 'phone_number', 'official_id_type',
            'nationality', 'birth_country', 'identification_country',
        ];
        if (in_array($fieldPath, $camposDirectos, true)) {
            if ($fieldPath === 'birth_country' || $fieldPath === 'identification_country') {
                $newValue = match (mb_strtoupper(trim((string) $newValue), 'UTF-8')) {
                    'MÉXICO', 'MEXICO', 'MEXICANA', 'MEXICANO', 'MX' => 'MX',
                    'ESTADOS UNIDOS', 'USA', 'EEUU', 'US' => 'US',
                    'HAITÍ', 'HAITI', 'HT' => 'HT',
                    'COLOMBIA', 'CO' => 'CO',
                    'VENEZUELA', 'VE' => 'VE',
                    'GUATEMALA', 'GT' => 'GT',
                    'HONDURAS', 'HN' => 'HN',
                    'EL SALVADOR', 'SV' => 'SV',
                    'NICARAGUA', 'NI' => 'NI',
                    'CUBA', 'CU' => 'CU',
                    default => mb_substr(mb_strtoupper(trim((string) $newValue), 'UTF-8'), 0, 2),
                };
            }

            if ($fieldPath === 'nationality') {
                $newValue = match (mb_strtoupper(trim((string) $newValue), 'UTF-8')) {
                    'MEXICANA', 'MEXICANO', 'MÉXICO', 'MEXICO', 'MX' => 'MEXICAN',
                    default => 'FOREIGN',
                };
            }

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
            $previous = $datos->has_identification_evidence ?? null;
            $datos->forceFill(['has_identification_evidence' => $newValue])->save();

            return [$previous, $newValue];
        }
        if ($columnas === null || ! is_string($newValue)) {
            throw new BusinessException('APPLICATION_CORRECTION_FIELD_INVALID', 'Campo de corrección no permitido.', 422);
        }

        [$ciphertext, $hmac, $metodoCifrado, $metodoHmac] = $columnas;
        $anteriorCifrado = $datos->getAttribute($ciphertext);
        $anteriorDescifrado = null;
        if ($anteriorCifrado) {
            try {
                $anteriorDescifrado = $protector->descifrar($anteriorCifrado);
            } catch (\Throwable) {
                $anteriorDescifrado = $anteriorCifrado;
            }
        }
        $nuevoNormalizado = match ($fieldPath) {
            'curp' => $protector->normalizarCurp($newValue),
            'rfc' => $protector->normalizarRfc($newValue),
            default => mb_strtoupper(trim($newValue)),
        };
        if ($fieldPath === 'curp' && preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $nuevoNormalizado) !== 1) {
            throw new BusinessException('APPLICATION_CORRECTION_CURP_INVALID', 'La CURP corregida no tiene un formato válido.', 422);
        }
        if ($fieldPath === 'curp') {
            $nuevoHmac = $protector->generarHmacCurp($nuevoNormalizado);
            if (DatosPersonalesSolicitud::query()
                ->where('curp_hmac', $nuevoHmac)
                ->whereKeyNot($datos->id)
                ->exists()) {
                throw new BusinessException('APPLICATION_CORRECTION_CURP_EXISTS', 'La CURP ya está registrada en otra solicitud.', 409);
            }
        }
        $nuevoCifrado = $protector->{$metodoCifrado}($nuevoNormalizado);
        $datos->forceFill([
            $ciphertext => $nuevoCifrado,
            $hmac => $protector->{$metodoHmac}($nuevoNormalizado),
        ])->save();

        return [$anteriorDescifrado, $nuevoNormalizado];
    }

    private function campoCanonico(string $fieldPath): string
    {
        return $fieldPath === 'curp_masked' ? 'curp' : $fieldPath;
    }

    private function valorAuditable(string $fieldPath, mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->valorAuditable('', $item), $value);
        }
        if (! is_string($value) || $value === '') {
            return $value;
        }
        if ($fieldPath === 'curp') {
            return str_contains($value, '*') ? $value : $this->enmascarar($value, 4, 3);
        }
        if (in_array($fieldPath, ['rfc', 'official_id_number'], true)) {
            return str_contains($value, '*') ? $value : $this->enmascarar($value, 2, 2);
        }

        return $value;
    }

    private function enmascarar(string $value, int $inicio, int $final): string
    {
        $longitud = mb_strlen($value);
        if ($longitud <= $inicio + $final) {
            return str_repeat('*', $longitud);
        }

        return mb_substr($value, 0, $inicio).str_repeat('*', $longitud - $inicio - $final).mb_substr($value, -$final);
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

        $newValue = $this->normalizarValorRegistro($fieldPath, $newValue, $record);
        $previous = $record->getAttribute($fieldPath);
        if ($fieldPath === 'proof_reference') {
            return [$previous, $newValue];
        }
        $record->setAttribute($fieldPath, $newValue);
        $record->save();

        return [$previous, $record->getAttribute($fieldPath)];
    }

    /**
     * Las diferencias se pueden capturar desde la visita y corregir después
     * desde coordinación. Esta capa no puede confiar en que ambas pantallas
     * hayan aplicado los mismos límites, por eso normaliza los valores que
     * van a terminar en el expediente canónico.
     */
    private function normalizarValorDatosPersonales(string $fieldPath, mixed $newValue): mixed
    {
        if (! is_scalar($newValue) && $newValue !== null) {
            $this->valorInvalido($fieldPath, 'El valor corregido no tiene un formato válido.');
        }

        return match ($fieldPath) {
            'birth_date' => $this->normalizarFechaNacimiento($newValue),
            'phone_number' => $this->normalizarTelefono($newValue),
            'curp' => $this->normalizarCurp($newValue),
            'rfc' => $this->normalizarRfc($newValue),
            'official_id_number' => $this->normalizarIdentificacion($newValue),
            'email' => $this->normalizarCorreo($newValue),
            'has_identification_evidence' => $this->normalizarBooleano($fieldPath, $newValue),
            default => is_string($newValue) ? trim($newValue) : $newValue,
        };
    }

    private function normalizarValorRegistro(string $fieldPath, mixed $newValue, Model $record): mixed
    {
        if (! is_scalar($newValue) && $newValue !== null) {
            $this->valorInvalido($fieldPath, 'El valor corregido no tiene un formato válido.');
        }

        if ($fieldPath === 'birth_date') {
            return $this->normalizarFechaNacimiento($newValue);
        }

        if ($fieldPath === 'started_at') {
            $date = $this->normalizarFecha($fieldPath, $newValue, 'La fecha de inicio debe tener el formato AAAA-MM-DD.');
            if ($date->isFuture()) {
                $this->valorInvalido($fieldPath, 'La fecha de inicio no puede ser posterior a hoy.');
            }
            $endedAt = $record->getAttribute('ended_at');
            if ($endedAt !== null && $date->greaterThan(CarbonImmutable::parse($endedAt))) {
                $this->valorInvalido($fieldPath, 'La fecha de inicio no puede ser posterior a la fecha de terminación.');
            }

            return $date->toDateString();
        }

        if ($fieldPath === 'ended_at') {
            $date = $this->normalizarFecha($fieldPath, $newValue, 'La fecha de terminación debe tener el formato AAAA-MM-DD.');
            if ($date->isFuture()) {
                $this->valorInvalido($fieldPath, 'La fecha de terminación no puede ser posterior a hoy.');
            }
            $startedAt = $record->getAttribute('started_at');
            if ($startedAt !== null && $date->lessThan(CarbonImmutable::parse($startedAt))) {
                $this->valorInvalido($fieldPath, 'La fecha de terminación debe ser igual o posterior a la fecha de inicio.');
            }

            return $date->toDateString();
        }

        if (in_array($fieldPath, ['is_current', 'is_active'], true)) {
            return $this->normalizarBooleano($fieldPath, $newValue);
        }

        if ($fieldPath === 'model_year') {
            return $this->normalizarAnioModelo($newValue);
        }

        if (in_array($fieldPath, ['amount', 'outstanding_balance', 'monthly_payment', 'credit_limit'], true)) {
            return $this->normalizarMonto($fieldPath, $newValue);
        }

        if (in_array($fieldPath, ['width_meters', 'length_meters', 'built_area_square_meters'], true)) {
            return $this->normalizarDimension($fieldPath, $newValue);
        }

        return is_string($newValue) ? trim($newValue) : $newValue;
    }

    private function normalizarAnioModelo(mixed $value): int
    {
        $raw = trim((string) $value);
        $maxYear = now()->year + 1;
        if (preg_match('/^\d{4}$/', $raw) !== 1 || (int) $raw < 1990 || (int) $raw > $maxYear) {
            $this->valorInvalido('model_year', "El año del vehículo debe estar entre 1990 y {$maxYear}.");
        }

        return (int) $raw;
    }

    private function normalizarMonto(string $fieldPath, mixed $value): string
    {
        $raw = trim((string) $value);
        if (preg_match('/^\d{1,15}(?:\.\d{1,4})?$/', $raw) !== 1) {
            $this->valorInvalido($fieldPath, 'El valor debe ser un número válido con hasta 4 decimales y sin signo negativo.');
        }

        return bcadd($raw, '0.0000', 4);
    }

    private function normalizarDimension(string $fieldPath, mixed $value): string
    {
        $raw = trim((string) $value);
        if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw) !== 1 || bccomp($raw, '0', 4) <= 0) {
            $this->valorInvalido($fieldPath, 'La medida debe ser un número válido mayor que cero y con hasta 2 decimales.');
        }

        return bcadd($raw, '0.00', 2);
    }

    private function normalizarTelefono(mixed $value): string
    {
        $raw = trim((string) $value);
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($raw, '+') && strlen($digits) >= 11 && strlen($digits) <= 14) {
            return '+'.substr($digits, 0, -10).substr($digits, -10);
        }
        if (strlen($digits) !== 10) {
            $this->valorInvalido('phone_number', 'El teléfono debe contener exactamente 10 dígitos nacionales.');
        }

        // La corrección captura el número nacional; en el expediente se guarda
        // en formato internacional, igual que el control principal de teléfono.
        return '+52'.$digits;
    }

    private function normalizarFechaNacimiento(mixed $value): string
    {
        $date = $this->normalizarFecha('birth_date', $value, 'La fecha de nacimiento debe tener el formato AAAA-MM-DD.');
        if ($date->lessThan(CarbonImmutable::create(1900, 1, 1))) {
            $this->valorInvalido('birth_date', 'La fecha de nacimiento debe ser igual o posterior al 01/01/1900.');
        }
        if ($date->greaterThan(today()->subYears(18))) {
            $this->valorInvalido('birth_date', 'La persona debe tener al menos 18 años.');
        }

        return $date->toDateString();
    }

    private function normalizarFecha(string $fieldPath, mixed $value, string $message): CarbonImmutable
    {
        $raw = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            $this->valorInvalido($fieldPath, $message);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $raw);
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $raw) {
            $this->valorInvalido($fieldPath, $message);
        }

        return $date;
    }

    private function normalizarCurp(mixed $value): string
    {
        $curp = mb_strtoupper(trim((string) $value), 'UTF-8');
        if (preg_match('/^[A-Z\d]{18}$/', $curp) !== 1) {
            $this->valorInvalido('curp', 'La CURP debe contener exactamente 18 caracteres alfanuméricos.');
        }

        return $curp;
    }

    private function normalizarRfc(mixed $value): string
    {
        $rfc = mb_strtoupper(trim((string) $value), 'UTF-8');
        if (preg_match('/^([A-ZÑ&]{3,4})(\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01]))([A-Z\d]{2})([A\d])$/u', $rfc) !== 1) {
            $this->valorInvalido('rfc', 'El RFC debe tener un formato válido de 12 o 13 caracteres.');
        }

        return $rfc;
    }

    private function normalizarIdentificacion(mixed $value): string
    {
        $identificacion = trim((string) $value);
        $length = mb_strlen($identificacion, 'UTF-8');
        if ($length < 3 || $length > 25) {
            $this->valorInvalido('official_id_number', 'El número de identificación debe tener entre 3 y 25 caracteres.');
        }

        return $identificacion;
    }

    private function normalizarCorreo(mixed $value): string
    {
        $email = mb_strtolower(trim((string) $value), 'UTF-8');
        if ($email === '' || mb_strlen($email, 'UTF-8') > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->valorInvalido('email', 'El correo electrónico debe tener un formato válido.');
        }

        return $email;
    }

    private function normalizarBooleano(string $fieldPath, mixed $value): bool
    {
        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized === null) {
            $this->valorInvalido($fieldPath, 'Selecciona un valor válido: sí o no.');
        }

        return $normalized;
    }

    private function valorInvalido(string $fieldPath, string $message): void
    {
        throw new ApiException(
            'APPLICATION_CORRECTION_VALUE_INVALID',
            $message,
            422,
            [$fieldPath => [$message]],
        );
    }

    public function finalizarCorrecciones(string $applicationId, string $coordinatorId, int $lockVersion, bool $force = false): void
    {
        $this->asegurarCoordinador($coordinatorId);

        DB::transaction(function () use ($applicationId, $coordinatorId, $lockVersion, $force) {
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
            if ($visit === null) {
                throw new BusinessException('VERIFICATION_VISIT_NOT_FOUND', 'Visita no encontrada.', 404);
            }
            $differences = $visit->differences_payload['items'] ?? [];
            $correctionCount = ApplicationCorrection::where('application_id', $applicationId)
                ->where('verification_visit_id', $visit->id)
                ->whereNotNull('difference_index')
                ->distinct()
                ->count('difference_index');
            if (! $force && $correctionCount < count($differences)) {
                throw new BusinessException('APPLICATION_CORRECTIONS_PENDING', 'Faltan diferencias por corregir.', 409);
            }

            $estadoAnterior = $application->status->value;
            $application->transitionTo(ApplicationStatus::COORDINATOR_EVALUATION, $coordinatorId, 'Correcciones terminadas');

            AuditHelper::log(
                'APPLICATION_CORRECTIONS_COMPLETED',
                'DistributorApplication',
                $application->id,
                $coordinatorId,
                $application->branch_id,
                ['status' => $estadoAnterior, 'correction_count' => $correctionCount],
                ['status' => ApplicationStatus::COORDINATOR_EVALUATION->value, 'correction_count' => $correctionCount],
                'Etapa terminada',
                'SUCCESS',
                $application->lock_version,
            );
        });
    }

    private function asegurarCoordinador(string $userId): void
    {
        $user = \App\Models\User::query()->find($userId);
        if ($user === null || ! $user->hasRole('coordinator')) {
            throw new BusinessException('AUTH_SCOPE_DENIED', 'No tienes permiso para gestionar correcciones de solicitudes.', 403);
        }
    }
}
