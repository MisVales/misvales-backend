<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use App\Http\Requests\Traits\ValidaDireccionEstructurada;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CrearClienteRequest extends FormRequest
{
    use ValidaDireccionEstructurada;

    protected function prepareForValidation(): void
    {
        $curp = $this->input('curp');
        if (! empty($curp)) {
            $curp = mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($curp)));
            $this->merge(['curp' => $curp]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:120'],
            'first_last_name' => ['required', 'string', 'max:120'],
            'second_last_name' => ['nullable', 'string', 'max:120'],
            'curp' => ['required', 'string', 'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/'],
            'rfc' => ['nullable', 'string'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'birth_place' => ['required', 'string', 'max:160'],
            'birth_state' => ['required', 'string', 'max:120'],
            'birth_city' => ['required', 'string', 'max:120'],
            'official_id_type' => ['required', Rule::in(['INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'])],
            'official_id_number' => ['nullable', 'string', 'max:50'],
            'official_id_media_id' => ['nullable', 'uuid', 'exists:media_files,id'],

            'address' => ['required', 'array'],
            'address.address_proof_media_id' => ['nullable', 'uuid', 'exists:media_files,id'],

            'address.street' => ['nullable', 'string'],
            'address.exterior_number' => ['nullable', 'string'],
            'address.interior_number' => ['nullable', 'string'],
            'address.neighborhood' => ['nullable', 'string'],
            'address.postal_code' => ['nullable', 'string'],
            'address.municipality' => ['nullable', 'string'],
            'address.city' => ['nullable', 'string'],
            'address.state' => ['nullable', 'string'],

            'bank_account' => ['required', 'array'],
            'bank_account.bank_name' => ['required', 'string', 'max:160'],
            'bank_account.account_holder_name' => ['required', 'string', 'max:240'],
            'bank_account.account_number' => ['nullable', 'string'],
            'bank_account.clabe' => ['required_without:bank_account.account_number', 'nullable', 'string'],
        ];

        return $rules;
    }

    public function messages(): array
    {
        return [
            'curp.regex' => 'La CURP no tiene un formato válido.',
            'birth_date.after_or_equal' => 'La fecha de nacimiento debe ser igual o posterior al 01/01/1900.',
            'birth_date.before_or_equal' => 'La fecha de nacimiento no puede estar en el futuro.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_VALIDATION_FAILED', 'Los datos enviados no cumplen las reglas del registro de cliente.', 422, $validator->errors()->toArray(), []);
    }
}
