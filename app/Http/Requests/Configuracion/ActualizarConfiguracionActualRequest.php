<?php

namespace App\Http\Requests\Configuracion;

use App\Models\ConfigurationDefinition;
use Illuminate\Foundation\Http\FormRequest;

class ActualizarConfiguracionActualRequest extends FormRequest
{
    use ValidatesConfigurationValues;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $configuracion = ConfigurationDefinition::query()
            ->where('key', $this->route('key'))
            ->first();

        return array_merge([
            'reason' => ['required', 'string', 'min:10'],
        ], $configuracion
            ? $this->getValueRulesForType($configuracion->value_type, $configuracion->key)
            : ['value' => ['required']]);
    }
}
