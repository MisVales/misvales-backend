<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use App\Http\Requests\Traits\ValidaDireccionEstructurada;
use App\Models\Cliente;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CrearBorradorClienteRequest extends FormRequest
{
    use ValidaDireccionEstructurada;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Cliente::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
            'first_last_name' => ['required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
            'second_last_name' => ['required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
            'curp' => ['required', 'string', 'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'phone_number' => ['required', 'string', 'regex:/^\+\d{1,4}\d{10}$/'],
            'address' => ['required', 'array'],
            ...$this->reglasDireccionEstructurada('address'),
            ...$this->reglasCodigoPostalMexicano('address'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $curp = $this->input('curp');
        if (! empty($curp)) {
            $curp = mb_strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', trim($curp)));
            $this->merge(['curp' => $curp]);
        }
    }

    public function messages(): array
    {
        return [
            'curp.required' => 'La CURP es obligatoria.',
            'curp.regex' => 'La CURP no tiene un formato válido.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_REGISTRATION_DRAFT_INVALID', 'Completa correctamente los datos del cliente.', 422, $validator->errors()->toArray(), []);
    }
}
