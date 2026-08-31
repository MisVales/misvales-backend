<?php

namespace App\Services\VerificacionDistribuidora;

use Carbon\CarbonImmutable;

final class ValidadorValorVerificacion
{
    public static function mensaje(string $field, mixed $value, bool $requerido = false): ?string
    {
        if ($value === null || $value === '') {
            return $requerido && self::requiereValor($field)
                ? 'Este campo requiere un valor valido para registrar la diferencia.'
                : null;
        }

        if (! is_scalar($value)) {
            return 'El valor debe ser escalar y tener un formato valido.';
        }

        $raw = trim((string) $value);

        if (in_array($field, ['amount', 'outstanding_balance', 'monthly_payment', 'credit_limit'], true)
            && preg_match('/^\d{1,15}(?:\.\d{1,4})?$/', $raw) !== 1) {
            return 'El valor debe ser numerico, sin letras ni signo negativo, y admitir hasta 4 decimales.';
        }

        if (in_array($field, ['width_meters', 'length_meters', 'built_area_square_meters'], true)
            && (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $raw) !== 1 || (float) $raw <= 0)) {
            return 'La medida debe ser numerica, mayor que cero y admitir hasta 2 decimales.';
        }

        if ($field === 'model_year') {
            $maxYear = now()->year + 1;
            if (preg_match('/^\d{4}$/', $raw) !== 1 || (int) $raw < 1990 || (int) $raw > $maxYear) {
                return "El ano del vehiculo debe estar entre 1990 y {$maxYear}.";
            }
        }

        if (in_array($field, ['birth_date', 'started_at', 'ended_at'], true)) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
                return 'La fecha debe tener el formato AAAA-MM-DD.';
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $raw);
            } catch (\Throwable) {
                $date = null;
            }
            if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $raw) {
                return 'La fecha no es valida.';
            }
            if ($field === 'birth_date' && ($date->lessThan(CarbonImmutable::create(1900, 1, 1)) || $date->greaterThan(today()->subYears(18)))) {
                return 'La fecha de nacimiento debe corresponder a una persona mayor de edad.';
            }
            if (in_array($field, ['started_at', 'ended_at'], true) && $date->isFuture()) {
                return 'La fecha no puede ser posterior a hoy.';
            }
        }

        if ($field === 'phone_number') {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if ((str_starts_with($raw, '+') && (strlen($digits) < 11 || strlen($digits) > 14))
                || (! str_starts_with($raw, '+') && strlen($digits) !== 10)) {
                return 'El telefono debe contener 10 digitos nacionales o un numero internacional valido.';
            }
        }

        if (in_array($field, ['curp', 'curp_masked'], true)
            && preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z\d]\d$/i', $raw) !== 1) {
            return 'La CURP debe tener un formato valido de 18 caracteres.';
        }

        if ($field === 'rfc'
            && preg_match('/^([A-Z\x{00D1}&]{3,4})(\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01]))([A-Z\d]{2})([A\d])$/iu', $raw) !== 1) {
            return 'El RFC debe tener un formato valido de 12 o 13 caracteres.';
        }

        if ($field === 'nationality' && ! in_array(strtoupper($raw), ['MEXICAN', 'FOREIGN'], true)) {
            return 'Selecciona una nacionalidad valida.';
        }

        if (in_array($field, ['birth_country', 'identification_country', 'country'], true)
            && preg_match('/^[A-Z]{2}$/', strtoupper($raw)) !== 1) {
            return 'Selecciona un pais valido.';
        }

        if ($field === 'official_id_type' && ! in_array(strtoupper($raw), ['INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'], true)) {
            return 'Selecciona un tipo de identificacion valido.';
        }

        if (in_array($field, ['is_current', 'is_active', 'has_identification_evidence', 'has_evidence', 'economic_dependency', 'is_family_reference'], true)
            && ! in_array(strtolower($raw), ['true', 'false', '1', '0'], true)) {
            return 'Selecciona un valor booleano valido.';
        }

        return null;
    }

    private static function requiereValor(string $field): bool
    {
        return in_array($field, [
            'amount', 'outstanding_balance', 'monthly_payment', 'credit_limit',
            'width_meters', 'length_meters', 'built_area_square_meters', 'model_year',
            'birth_date', 'started_at', 'ended_at', 'phone_number', 'curp', 'curp_masked',
            'rfc', 'nationality', 'birth_country', 'identification_country', 'country',
            'official_id_type', 'is_current', 'is_active', 'has_identification_evidence',
            'has_evidence', 'economic_dependency', 'is_family_reference',
        ], true);
    }
}
