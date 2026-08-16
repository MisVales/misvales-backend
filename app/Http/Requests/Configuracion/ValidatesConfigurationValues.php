<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\ConfigurationValueType;

trait ValidatesConfigurationValues
{
    /**
     * Genera las reglas de validación dinámicas basadas en el tipo de valor y la llave (key).
     * Garantiza la integridad técnica (Puntos 22 al 26).
     *
     * @param  string|null  $type  El tipo de valor (e.g., 'INTEGER', 'DECIMAL', 'TIME')
     * @param  string|null  $key  La llave de configuración (e.g., 'CUT_DAY_OF_MONTH')
     * @return array Reglas de validación aplicables al campo 'value'
     */
    protected function getValueRulesForType(?string $type, ?string $key = null): array
    {
        $enumType = ConfigurationValueType::tryFrom($type ?? '');

        $rules = [];
        $rules['value'] = match ($enumType) {
            ConfigurationValueType::INTEGER => ['required', 'integer'],
            ConfigurationValueType::DECIMAL => ['required', 'numeric', 'min:0'],
            ConfigurationValueType::PERCENTAGE => ['required', 'numeric', 'min:0', 'max:1'],
            ConfigurationValueType::TIME => [
                'required',
                in_array($key, ['CUT_TIME', 'BANK_UPLOAD_DEADLINE_TIME', 'POST_DUE_EVALUATION_TIME'], true)
                    ? 'date_format:H:i'
                    : 'date_format:H:i:s',
            ],
            ConfigurationValueType::TIMEZONE => ['required', 'timezone'],
            ConfigurationValueType::DURATION => ['required', 'integer', 'min:0'],
            ConfigurationValueType::DATE => ['required', 'date_format:Y-m-d'],
            ConfigurationValueType::TIME_RANGE => ['required', 'array', 'size:2'],
            ConfigurationValueType::STRING => ['required', 'string'],
            ConfigurationValueType::JSON => ['required', 'array'],
            default => ['required'],
        };

        if ($enumType === ConfigurationValueType::TIME_RANGE) {
            $rules['value.*'] = ['required', 'date_format:H:i:s'];
        }

        if ($key) {
            if ($key === 'EARLY_PAYMENT_PERIOD') {
                $rules['value.start'] = ['required', 'integer', 'min:0'];
                $rules['value.end'] = ['required', 'integer', 'gt:value.start']; // Punto 28
            } elseif ($key === 'RELATION_PAYMENT_BANK') {
                $rules['value.name'] = ['required', 'string', 'max:160'];
                $rules['value.beneficiary'] = ['required', 'string', 'max:255'];
                $rules['value.agreement'] = ['required', 'string', 'max:100'];
                $rules['value.clabe'] = ['required', 'regex:/^\d{18}$/'];
            } else {
                $specific = match ($key) {
                    'CUT_DAY_OF_MONTH' => ['between:1,28'], // Punto 23
                    'PAYMENT_DAYS_AFTER_CUT', 'POINTS_MULTIPLIER' => ['min:1'], // Punto 24 y 26
                    'MODIFICATION_TOKEN_TTL' => ['min:1', 'max:1440'], // Punto 26
                    'CREDIT_TOLERANCE_AMOUNT', 'LATE_FEE_AMOUNT', 'POINTS_DIVISOR_AMOUNT', 'POINT_VALUE_AMOUNT' => ['min:0', 'max:99999999.9999'], // Punto 26
                    'LATE_POINTS_REDUCTION_RATE' => ['min:0', 'max:1'], // Punto 26
                    default => [],
                };

                $rules['value'] = array_merge($rules['value'], $specific);
            }
        }

        return $rules;
    }
}
