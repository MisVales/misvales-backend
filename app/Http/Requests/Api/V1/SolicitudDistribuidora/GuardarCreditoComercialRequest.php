<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Http\Requests\AllowsPartialDrafts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarCreditoComercialRequest extends FormRequest
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
            'company_name' => [$presencia, 'string', 'max:180'],
            'credit_limit' => [$presencia, 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'is_current' => ['sometimes', 'boolean'],
            'proof_reference' => ['nullable', 'uuid'],
            'details_payload' => ['nullable', 'array'],
            'details_payload.proof_type' => ['required', Rule::in(['CARTA', 'ESTADO_DE_CUENTA'])],
        ];

        return $this->applyDraftRules($rules, ['lock_version']);
    }

    public function messages(): array
    {
        return [
            'details_payload.proof_type.required' => 'Debes seleccionar el comprobante del crédito comercial.',
            'details_payload.proof_type.in' => 'El comprobante del crédito comercial no es válido.',
        ];
    }
}
