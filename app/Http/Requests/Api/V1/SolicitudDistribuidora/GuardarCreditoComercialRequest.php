<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;

final class GuardarCreditoComercialRequest extends FormRequest
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
            'company_name' => [$presencia, 'string', 'max:180'],
            'credit_limit' => [$presencia, 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'],
            'is_current' => ['sometimes', 'boolean'],
            'proof_reference' => ['nullable', 'uuid'],
            'details_payload' => ['nullable', 'array'],
        ];
    }
}
