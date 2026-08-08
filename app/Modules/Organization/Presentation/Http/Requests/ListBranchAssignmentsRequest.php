<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class ListBranchAssignmentsRequest extends OrganizationFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('include_history')) {
            $this->merge(['include_history' => $this->boolean('include_history')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'role_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'string', Rule::in(['ACTIVE', 'ENDED', 'REVOKED'])],
            'include_history' => ['sometimes', 'boolean'],
        ];
    }
}
