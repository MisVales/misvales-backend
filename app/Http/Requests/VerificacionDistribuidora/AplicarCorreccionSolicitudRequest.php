<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Enums\ApplicationCorrectionSection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AplicarCorreccionSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'seccion' => ['required', Rule::enum(ApplicationCorrectionSection::class)],
            'campo' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'valor_observado' => 'present',
            'valor_corregido' => 'present',
            'motivo' => 'required|string|max:1000',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
