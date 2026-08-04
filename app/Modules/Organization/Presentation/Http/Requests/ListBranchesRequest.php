<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Validation\Rule;

final class ListBranchesRequest extends OrganizationFormRequest
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
            'status' => ['sometimes', 'string', Rule::in(['ACTIVE', 'INACTIVE'])],
            'search' => ['sometimes', 'string', 'max:150'],
            'sort' => ['sometimes', 'string', Rule::in(['code', 'name', 'status', 'created_at'])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
