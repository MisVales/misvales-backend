<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class FeriarValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['CASH', 'TRANSFER'])],
            'bank_transaction_number' => ['nullable', 'required_if:payment_method,TRANSFER', 'string', 'max:25', 'regex:/^\d+$/'],
            'clabe' => ['nullable', 'required_if:payment_method,TRANSFER', 'string', 'regex:/^\d{18}$/'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_transaction_number.required' => 'El número de transacción es obligatorio.',
            'bank_transaction_number.max' => 'El número de transacción no debe exceder 25 dígitos.',
            'bank_transaction_number.regex' => 'El número de transacción solo puede contener números.',
        ];
    }
}
