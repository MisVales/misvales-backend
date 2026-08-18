<?php

namespace App\Http\Requests\Api\V1\SolicitudDistribuidora;

use App\Http\Requests\AllowsPartialDrafts;
use Illuminate\Foundation\Http\FormRequest;

final class GuardarEmpleoRequest extends FormRequest
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

        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'employer_name' => [$presencia, 'string', 'max:180'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'started_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'ended_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:started_at'],
            'is_current' => ['sometimes', 'boolean'],
            'reference_payload' => ['nullable', 'array'],
            'details_payload' => ['nullable', 'array'],
        ];
    }
}
