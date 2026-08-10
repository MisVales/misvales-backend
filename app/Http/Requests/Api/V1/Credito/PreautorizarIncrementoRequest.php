<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class PreautorizarIncrementoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $solicitud = $this->route('solicitud');
        $max = $solicitud ? $solicitud->requested_amount : '0.00';

        return [
            'monto_recomendado' => ['required', 'numeric', 'min:1', "max:{$max}"],
            'notas' => ['nullable', 'string', 'max:500'],
        ];
    }
}
