<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

final class StoreBranchRequest extends OrganizationFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/'],
            'name' => ['required', 'string', 'max:150'],
        ];
    }
}
