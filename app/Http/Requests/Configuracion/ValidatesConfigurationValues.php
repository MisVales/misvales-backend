<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\ConfigurationValueType;

trait ValidatesConfigurationValues
{
    /**
     * Genera las reglas de validación dinámicas basadas en el tipo de valor y la llave (key).
     * Garantiza la integridad técnica de los valores publicados.
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
            if ($key === 'RELATION_PAYMENT_BANK') {
                $rules['value.name'] = ['required', 'string', 'max:160'];
                $rules['value.beneficiary'] = ['required', 'string', 'max:255'];
                $rules['value.agreement'] = ['required', 'string', 'max:100'];
                $rules['value.clabe'] = ['required', 'regex:/^\d{18}$/'];
            } else {
                $specific = match ($key) {
                    'CUT_DAY_OF_MONTH' => ['between:1,28'],
                    'PAYMENT_DAYS_AFTER_CUT' => ['min:1'],
                    'CREDIT_TOLERANCE_AMOUNT', 'LATE_FEE_AMOUNT' => ['min:0', 'max:99999999.9999'],
                    default => [],
                };

                $rules['value'] = array_merge($rules['value'], $specific);
            }
        }

        return $rules;
    }
}
