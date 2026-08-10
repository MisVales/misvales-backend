<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class CrearSolicitudIncrementoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autenticación manejada por middleware y policy
    }

    public function rules(): array
    {
        return [
            'monto_solicitado' => ['required', 'numeric', 'min:1'],
            'notas' => ['nullable', 'string', 'max:500'],
        ];
    }
}
