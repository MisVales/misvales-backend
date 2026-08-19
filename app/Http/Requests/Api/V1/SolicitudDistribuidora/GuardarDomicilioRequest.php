<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Http\Requests\AllowsPartialDrafts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarDomicilioRequest extends FormRequest
{
    use AllowsPartialDrafts, \App\Http\Requests\Traits\ValidaDireccionEstructurada, RechazaPropiedadesDesconocidas;

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
            'is_current' => [$presencia, 'boolean'],
            'housing_tenure' => [$presencia, Rule::in(['OWNED', 'RENTED', 'BORROWED', 'OTHER'])],
            'financing_status' => ['nullable', Rule::in(['PAID', 'MORTGAGE', 'LOAN', 'INFONAVIT', 'OTHER', 'NOT_APPLICABLE'])],
            'width_meters' => ['nullable', 'decimal:0,2', 'gt:0'],
            'length_meters' => ['nullable', 'decimal:0,2', 'gt:0'],
            'built_area_square_meters' => ['nullable', 'decimal:0,2', 'gt:0'],
            'details_payload' => ['nullable', 'array'],
        ];

        return $this->applyDraftRules(array_merge(
            $rules,
            $this->reglasDireccionEstructurada('', $presencia),
            $this->reglasCodigoPostalMexicano('', $presencia)
        ), ['lock_version']);
    }

    public function messages(): array
    {
        return [
            'country.size' => 'Selecciona un país válido.',
            'width_meters.decimal' => 'El frente debe ser un número válido.',
            'width_meters.gt' => 'El frente debe ser mayor que cero.',
            'length_meters.decimal' => 'El fondo debe ser un número válido.',
            'length_meters.gt' => 'El fondo debe ser mayor que cero.',
            'built_area_square_meters.decimal' => 'El área construida debe ser un número válido.',
            'built_area_square_meters.gt' => 'El área construida debe ser mayor que cero.',
        ];
    }
}
