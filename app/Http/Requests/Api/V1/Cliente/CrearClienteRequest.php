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
        if (empty($curp)) {
            $randomDigits = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $randomEnd = str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
            $curp = "AAAA{$randomDigits}HAAAAA{$randomEnd}";
        } else {
            $curp = mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($curp)));
        }

        $this->merge([
            'curp' => $curp,
            'birth_date' => $this->input('birth_date', '2000-01-01'),
            'birth_place' => $this->input('birth_place', 'N/A'),
            'birth_state' => $this->input('birth_state', 'N/A'),
            'birth_city' => $this->input('birth_city', 'N/A'),
            'official_id_type' => $this->input('official_id_type', 'OTHER'),
            'address' => $this->input('address', [
                'street' => 'Conocido',
                'exterior_number' => 'SN',
                'neighborhood' => 'Centro',
                'postal_code' => '00000',
                'municipality' => 'N/A',
                'city' => 'N/A',
                'state' => 'N/A',
            ]),
            'bank_account' => $this->input('bank_account', [
                'bank_name' => 'N/A',
                'account_holder_name' => $this->input('first_name') . ' ' . $this->input('first_last_name'),
                'clabe' => '000000000000000000',
            ]),
        ]);
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
            'birth_date' => ['required', 'date_format:Y-m-d'],
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
        return [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_VALIDATION_FAILED', 'Los datos enviados no cumplen las reglas del registro de cliente.', 422, $validator->errors()->toArray(), []);
    }
}
