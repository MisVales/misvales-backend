<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GuardarPatrimonioRequest extends FormRequest
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
        $importe = ['nullable', 'string', 'regex:/^\d{1,15}(\.\d{1,4})?$/'];

        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'entry_type' => [$presencia, Rule::in(['ASSET', 'LIABILITY', 'ACTIVE_COMMITMENT'])],
            'name' => [$presencia, 'string', 'max:180'],
            'amount' => $importe,
            'outstanding_balance' => $importe,
            'monthly_payment' => $importe,
            'is_active' => ['sometimes', 'boolean'],
            'details_payload' => ['nullable', 'array'],
        ];
    }
}
