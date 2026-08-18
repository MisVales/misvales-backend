<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

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
        throw new ApiException('CLIENT_BANK_ACCOUNT_INVALID', 'Los datos de la cuenta bancaria no son vÃ¡lidos.', 422, $validator->errors()->toArray(), []);
    }
}
