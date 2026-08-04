<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Foundation\Http\FormRequest;

class TransicionVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Punto 46 y 47: Exigir motivo y lock_version para publicación y desactivación
        return [
            'reason' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
