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
        if (is_string($this->input('curp'))) {
            $this->merge([
                'curp' => mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($this->input('curp')))),
            ]);
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
            'rfc' => ['nullable', 'string', 'regex:/^[A-Za-zÃ±Ã‘&]{3,4}\d{6}[A-Za-z0-9]{3}$/'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'birth_place' => ['required', 'string', 'max:160'],
            'birth_state' => ['required', 'string', 'max:120'],
            'birth_city' => ['required', 'string', 'max:120'],
            'official_id_type' => ['required', Rule::in(['INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'])],
            'official_id_number' => ['nullable', 'string', 'max:80'],
            'official_id_media_id' => ['nullable', 'uuid', 'exists:media_files,id'],

            'address' => ['required', 'array'],
            'address.address_proof_media_id' => ['nullable', 'uuid', 'exists:media_files,id'],

            'bank_account' => ['required', 'array'],
            'bank_account.bank_name' => ['required', 'string', 'max:160'],
            'bank_account.account_holder_name' => ['required', 'string', 'max:240'],
            'bank_account.account_number' => ['nullable', 'string', 'regex:/^\d{4,30}$/'],
            'bank_account.clabe' => ['required', 'string', 'regex:/^\d{18}$/'],
        ];

        return array_merge(
            $rules,
            $this->reglasDireccionEstructurada('address', 'required'),
            $this->reglasCodigoPostalMexicano('address', 'required')
        );
    }

    public function messages(): array
    {
        return [
            'curp.regex' => 'La CURP no tiene un formato vÃ¡lido.',
            'bank_account.clabe.regex' => 'La CLABE debe contener exactamente 18 dÃ­gitos.',
            'address.postal_code.regex' => 'El cÃ³digo postal debe contener 5 dÃ­gitos.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_VALIDATION_FAILED', 'Los datos enviados no cumplen las reglas del registro de cliente.', 422, $validator->errors()->toArray(), []);
    }
}
