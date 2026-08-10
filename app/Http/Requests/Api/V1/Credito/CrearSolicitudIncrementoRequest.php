<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class CrearSolicitudIncrementoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('distributor');
    }

    public function rules(): array
    {
        return [
            'requested_amount' => [
                'required',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/', // Solo números positivos con hasta 4 decimales. Rechaza exponenciales (1e4)
                function ($attribute, $value, $fail) {
                    if (bccomp($value, '0.0000', 4) <= 0) {
                        $fail("El importe solicitado debe ser mayor que cero.");
                    }
                }
            ],
            'request_reason' => ['required', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
