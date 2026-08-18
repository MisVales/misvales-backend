<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class DecidirIncrementoGerenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Se maneja en la Policy
    }

    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:APPROVE_REQUESTED,APPROVE_LOWER,REJECT'],
            'reason' => ['required', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'authorized_amount' => [
                'required_if:decision,APPROVE_LOWER',
                'prohibited_unless:decision,APPROVE_LOWER',
                'string',
                'regex:/^\d+(\.\d{1,4})?$/',
                function ($attribute, $value, $fail) {
                    if ($this->input('decision') === 'APPROVE_LOWER') {
                        if (bccomp($value, '0.0000', 4) <= 0) {
                            $fail('El importe autorizado debe ser mayor que cero.');
                        }
                    }
                },
            ],
        ];
    }
}
