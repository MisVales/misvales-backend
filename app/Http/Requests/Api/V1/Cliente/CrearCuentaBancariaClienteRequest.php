<?php

namespace App\Http\Requests\Api\V1\Cliente;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CrearCuentaBancariaClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:160'],
            'account_holder_name' => ['required', 'string', 'max:240'],
            'account_number' => ['nullable', 'string', 'regex:/^\d{4,30}$/'],
            'clabe' => ['required', 'string', 'regex:/^\d{18}$/'],
            'change_reason' => ['required', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => [
            'code' => 'CLIENT_BANK_ACCOUNT_INVALID',
            'message' => 'Los datos de la cuenta bancaria no son válidos.',
            'fields' => $validator->errors(),
            'details' => (object) [],
            'request_id' => $this->attributes->get('request_id'),
        ]], 422));
    }
}
