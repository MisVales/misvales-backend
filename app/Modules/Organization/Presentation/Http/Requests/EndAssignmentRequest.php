<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

final class EndAssignmentRequest extends OrganizationFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'string', 'max:500']];
    }
}
