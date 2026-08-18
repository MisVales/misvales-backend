<?php

namespace App\Http\Requests\Configuracion;

use App\Models\ConfigurationDefinition;
use Illuminate\Foundation\Http\FormRequest;

class CrearVersionRequest extends FormRequest
{
    use ValidatesConfigurationValues;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $key = $this->route('key');
        $configuracion = ConfigurationDefinition::where('key', $key)->first();

        $rules = [
            'reason' => ['required', 'string'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ];

        if ($configuracion) {
            $typeRules = $this->getValueRulesForType($configuracion->value_type, $configuracion->key);
        } else {
            $typeRules = ['value' => ['required']];
        }

        return array_merge($rules, $typeRules);
    }
}
