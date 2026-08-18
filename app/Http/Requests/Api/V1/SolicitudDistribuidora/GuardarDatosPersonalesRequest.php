<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Http\Requests\AllowsPartialDrafts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarDatosPersonalesRequest extends FormRequest
{
    use AllowsPartialDrafts, RechazaPropiedadesDesconocidas;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'lock_version' => ['required', 'integer', 'min:1'],
            'nationality' => ['required', Rule::in(['MEXICAN', 'FOREIGN'])],
            'first_name' => ['required', 'string', 'max:120', 'regex:/^\pL[\pL\s.\'-]*$/u'],
            'first_last_name' => ['required', 'string', 'max:120', 'regex:/^\pL[\pL\s.\'-]*$/u'],
            'second_last_name' => ['nullable', 'string', 'max:120', 'regex:/^\pL[\pL\s.\'-]*$/u'],
            'curp' => ['required_if:nationality,MEXICAN', 'nullable', 'string', 'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i'],
            'rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.today()->subYears(18)->toDateString()],
            'birth_country' => ['required', 'string', 'max:2'],
            'birth_place' => ['nullable', 'string', 'max:150'],
            'birth_state' => ['required', 'string', 'max:100'],
            'birth_city' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'identification_country' => ['required_if:nationality,FOREIGN', 'nullable', 'string', 'max:2'],
            'official_id_type' => ['required', Rule::in(['INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'])],
            'official_id_number' => ['required', 'string', 'min:3', 'max:100'],
        ];

        return $this->applyDraftRules($rules, ['lock_version']);
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'La persona solicitante debe tener al menos 18 años.',
            'first_name.regex' => 'El nombre sólo puede contener letras.',
            'first_last_name.regex' => 'El apellido paterno sólo puede contener letras.',
            'second_last_name.regex' => 'El apellido materno sólo puede contener letras.',
        ];
    }
}
