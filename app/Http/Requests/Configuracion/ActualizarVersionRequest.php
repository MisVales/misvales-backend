<?php

namespace App\Http\Requests\Configuracion;

use App\Models\ConfigurationVersion;
use Illuminate\Foundation\Http\FormRequest;

class ActualizarVersionRequest extends FormRequest
{
    use ValidatesConfigurationValues;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $version = ConfigurationVersion::with('definition')->findOrFail($this->route('id'));
        $configuracion = $version->definition;

        // Punto 46 y 47: Exigir motivo y lock_version para modificar
        $rules = [
            'reason' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
        ];

        if ($this->has('value')) {
            $typeRules = $this->getValueRulesForType($configuracion->value_type, $configuracion->key);
            $rules = array_merge($rules, $typeRules);
        }

        return $rules;
    }
}
