<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class PreautorizarIncrementoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Se maneja en la Policy
    }

    public function rules(): array
    {
        return [
            'recommended_amount' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/',
                function ($attribute, $value, $fail) {
                    if (bccomp($value, '0.0000', 4) <= 0) {
                        $fail("El importe recomendado debe ser mayor que cero.");
                    }
                }
            ],
            'reason' => ['required', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
