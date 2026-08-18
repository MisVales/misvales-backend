<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use App\Http\Requests\Traits\ValidaDireccionEstructurada;

final class UpdateBranchRequest extends OrganizationFormRequest
{
    use ValidaDireccionEstructurada;

    protected function prepareForValidation(): void
    {
        if ($this->has('lock_version') || ! $this->hasHeader('If-Match')) {
            return;
        }

        $value = preg_replace('/\AW\/|["\s]/', '', (string) $this->header('If-Match'));

        if (is_string($value) && ctype_digit($value)) {
            $this->merge(['lock_version' => (int) $value]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'validated_address' => ['nullable', 'array'],
            'lat' => ['nullable', 'numeric'],
            'lng' => ['nullable', 'numeric'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];

        return array_merge(
            $rules,
            $this->reglasDireccionEstructurada('validated_address', 'nullable'),
            $this->reglasCodigoPostalMexicano('validated_address', 'nullable')
        );
    }
}
