<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarDatosPersonalesRequest extends FormRequest
{
    use RechazaPropiedadesDesconocidas;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'first_name' => ['required', 'string', 'max:120'],
            'first_last_name' => ['required', 'string', 'max:120'],
            'second_last_name' => ['nullable', 'string', 'max:120'],
            'curp' => ['required', 'string', 'regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i'],
            'rfc' => ['nullable', 'string', 'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'before:today'],
            'birth_place' => ['required', 'string', 'max:150'],
            'birth_state' => ['required', 'string', 'max:100'],
            'birth_city' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'official_id_type' => ['required', Rule::in(['INE', 'PASSPORT', 'PROFESSIONAL_LICENSE', 'OTHER'])],
            'official_id_number' => ['required', 'string', 'min:3', 'max:100'],
        ];
    }
}
