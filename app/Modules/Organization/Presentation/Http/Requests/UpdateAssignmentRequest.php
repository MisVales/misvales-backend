<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

final class UpdateAssignmentRequest extends OrganizationFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'assigned_at' => ['sometimes', 'required_without:assignment_reason', 'date', 'before_or_equal:now'],
            'assignment_reason' => ['sometimes', 'required_without:assigned_at', 'string', 'min:1', 'max:500'],
        ];
    }
}
