<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Http\Requests\AllowsPartialDrafts;
use Illuminate\Foundation\Http\FormRequest;

final class GuardarVehiculoRequest extends FormRequest
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
            'vehicle_type' => [$presencia, 'string', 'max:64'],
            'brand' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'model_year' => ['nullable', 'integer', 'min:1886', 'max:'.(now()->year + 2)],
            'ownership_status' => ['nullable', 'string', 'max:32'],
            'details_payload' => ['nullable', 'array'],
        ];

        return $this->applyDraftRules($rules, ['lock_version']);
    }
}
