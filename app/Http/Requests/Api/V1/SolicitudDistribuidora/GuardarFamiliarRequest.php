<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarFamiliarRequest extends FormRequest
{
    use RechazaPropiedadesDesconocidas;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presencia = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'relationship' => [$presencia, Rule::in(['SPOUSE', 'PARTNER', 'CHILD', 'FATHER', 'MOTHER', 'SIBLING', 'OTHER'])],
            'first_name' => [$presencia, 'string', 'max:120'],
            'first_last_name' => [$presencia, 'string', 'max:120'],
            'second_last_name' => ['nullable', 'string', 'max:120'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'declared_age' => ['nullable', 'integer', 'min:0', 'max:130'],
            'school_name' => ['nullable', 'string', 'max:180'],
            'is_family_reference' => ['sometimes', 'boolean'],
            'details_payload' => ['nullable', 'array'],
        ];
    }
}
