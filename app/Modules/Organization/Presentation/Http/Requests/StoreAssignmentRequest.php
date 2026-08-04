<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class StoreAssignmentRequest extends OrganizationFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'scope' => ['sometimes', 'string', Rule::in(['GLOBAL', 'BRANCH', 'ASSIGNED', 'SELF'])],
            'assigned_at' => ['sometimes', 'date', 'before_or_equal:now'],
            'assignment_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
