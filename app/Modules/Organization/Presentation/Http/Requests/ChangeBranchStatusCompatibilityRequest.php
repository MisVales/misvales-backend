<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class ChangeBranchStatusCompatibilityRequest extends OrganizationFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['ACTIVE', 'INACTIVE'])],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
