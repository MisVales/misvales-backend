<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class ListPersonnelRequest extends OrganizationFormRequest
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
            'branch_id' => ['sometimes', 'uuid'],
            'role_id' => ['sometimes', 'uuid'],
            'user_state' => [
                'sometimes',
                'string',
                Rule::in(['INVITED', 'PENDING_ACTIVATION', 'ACTIVE', 'BLOCKED', 'DISABLED']),
            ],
            'assignment_status' => ['sometimes', 'string', Rule::in(['ACTIVE', 'ENDED', 'REVOKED'])],
        ];
    }
}
