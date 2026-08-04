<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearConfiguracionRequest extends FormRequest
{
    use ValidatesConfigurationValues;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'key' => ['required', 'string', 'max:255', 'unique:configuration_definitions,key'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'value_type' => ['required', new \Illuminate\Validation\Rules\Enum(\App\Enums\ConfigurationValueType::class)],
            'unit' => ['nullable', 'string', 'max:50'],
            'is_required' => ['boolean'],
            'is_sensitive' => ['boolean'],
            'reason' => ['required', 'string'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ];

        return array_merge($rules, $this->getValueRulesForType($this->input('value_type'), $this->input('key')));
    }
}
