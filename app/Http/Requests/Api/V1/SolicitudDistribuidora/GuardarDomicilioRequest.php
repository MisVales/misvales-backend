<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarDomicilioRequest extends FormRequest
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
            'is_current' => [$presencia, 'boolean'],
            'street' => [$presencia, 'string', 'max:150'],
            'exterior_number' => [$presencia, 'string', 'max:32'],
            'interior_number' => ['nullable', 'string', 'max:32'],
            'neighborhood' => [$presencia, 'string', 'max:150'],
            'postal_code' => [$presencia, 'string', 'regex:/^\d{5}$/'],
            'municipality' => [$presencia, 'string', 'max:120'],
            'city' => [$presencia, 'string', 'max:120'],
            'state' => [$presencia, 'string', 'max:120'],
            'country' => ['sometimes', 'string', 'size:2'],
            'housing_tenure' => [$presencia, Rule::in(['OWNED', 'RENTED', 'BORROWED', 'OTHER'])],
            'financing_status' => ['nullable', Rule::in(['PAID', 'MORTGAGE', 'LOAN', 'INFONAVIT', 'OTHER', 'NOT_APPLICABLE'])],
            'width_meters' => ['nullable', 'decimal:0,2', 'gt:0'],
            'length_meters' => ['nullable', 'decimal:0,2', 'gt:0'],
            'built_area_square_meters' => ['nullable', 'decimal:0,2', 'gt:0'],
            'details_payload' => ['nullable', 'array'],
        ];
    }
}
