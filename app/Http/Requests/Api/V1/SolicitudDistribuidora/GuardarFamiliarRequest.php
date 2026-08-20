<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Http\Requests\AllowsPartialDrafts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarFamiliarRequest extends FormRequest
{
    use AllowsPartialDrafts;
    use RechazaPropiedadesDesconocidas;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presencia = $this->isMethod('POST') ? 'required' : 'sometimes';

        $rules = [
            'lock_version' => ['required', 'integer', 'min:1'],
            'relationship' => [$presencia, Rule::in(['SPOUSE', 'PARTNER', 'CHILD', 'FATHER', 'MOTHER', 'SIBLING', 'OTHER'])],
            'first_name' => [$presencia, 'string', 'max:120', 'regex:/^\pL[\pL\s.\'-]*$/u'],
            'first_last_name' => [$presencia, 'string', 'max:120', 'regex:/^\pL[\pL\s.\'-]*$/u'],
            'second_last_name' => ['nullable', 'string', 'max:120', 'regex:/^\pL[\pL\s.\'-]*$/u'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.today()->subYears(18)->toDateString()],
            'school_name' => ['nullable', 'string', 'max:180'],
            'details_payload' => ['nullable', 'array'],
            'details_payload.other_relationship' => ['nullable', 'string', 'max:80', 'required_if:relationship,OTHER'],
        ];

        return $this->applyDraftRules($rules, ['lock_version']);
    }

    public function messages(): array
    {
        return [
            'birth_date.before_or_equal' => 'La referencia familiar debe tener al menos 18 años.',
            'birth_date.after_or_equal' => 'La fecha de nacimiento debe ser igual o posterior al 01/01/1900.',
            'first_name.regex' => 'El nombre sólo puede contener letras.',
            'first_last_name.regex' => 'El apellido paterno sólo puede contener letras.',
            'second_last_name.regex' => 'El apellido materno sólo puede contener letras.',
        ];
    }
}
