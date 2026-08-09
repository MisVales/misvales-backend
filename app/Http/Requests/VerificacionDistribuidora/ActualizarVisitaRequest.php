<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActualizarVisitaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'observaciones_generales' => 'nullable|string|max:2000',
            'latitud' => 'nullable|numeric|between:-90,90',
            'longitud' => 'nullable|numeric|between:-180,180',
            'precision_metros' => 'nullable|numeric|min:0|max:100000',
            'diferencias' => 'nullable|array|max:100',
            'diferencias.*.seccion' => ['required_with:diferencias', Rule::enum(ApplicationCorrectionSection::class)],
            'diferencias.*.campo' => ['required_with:diferencias', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'diferencias.*.dato_declarado' => 'present_with:diferencias',
            'diferencias.*.dato_observado' => 'present_with:diferencias',
            'diferencias.*.descripcion' => 'required_with:diferencias|string|max:1000',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
