<?php

namespace App\Http\Requests\Api\V1\Vale;

use App\Exceptions\ApiException;
use App\Http\Requests\Traits\ValidaDireccionEstructurada;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SolicitarModificacionValeRequest extends FormRequest
{
    use ValidaDireccionEstructurada;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => [Rule::in(['first_name', 'first_last_name', 'second_last_name', 'birth_date', 'phone_number', 'address', 'curp'])],
            'changes' => ['required', 'array'],
            'changes.first_name' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
            'changes.first_last_name' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
            'changes.second_last_name' => ['sometimes', 'required', 'string', 'max:120', 'regex:/^\p{L}[\p{L}\s.\'\-]*$/u'],
            'changes.birth_date' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'changes.phone_number' => ['sometimes', 'required', 'string', 'regex:/^\+\d{1,4}\d{10}$/'],
            'changes.curp' => ['sometimes', 'required', 'string', 'regex:/^[A-Z\d]{18}$/i'],
            'changes.address' => ['sometimes', 'required', 'array'],
            ...$this->reglasDireccionEstructurada('changes.address'),
            ...$this->reglasCodigoPostalMexicano('changes.address'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('MODIFICATION_VALIDATION_FAILED', 'Los datos de corrección no cumplen las validaciones del registro de cliente.', 422, $validator->errors()->toArray(), []);
    }
}
