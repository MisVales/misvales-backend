<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class ListBranchPersonnelRequest extends OrganizationFormRequest
{
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
            'state' => [
                'sometimes',
                'string',
                Rule::in(['INVITED', 'PENDING_ACTIVATION', 'ACTIVE', 'BLOCKED', 'DISABLED']),
            ],
        ];
    }
}
