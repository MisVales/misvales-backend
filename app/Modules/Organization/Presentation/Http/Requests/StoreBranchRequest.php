<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use App\Http\Requests\Traits\ValidaDireccionEstructurada;

final class StoreBranchRequest extends OrganizationFormRequest
{
    use ValidaDireccionEstructurada;

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
        ];

        return array_merge(
            $rules,
            $this->reglasDireccionEstructurada('validated_address', 'nullable'),
            $this->reglasCodigoPostalMexicano('validated_address', 'nullable')
        );
    }
}
