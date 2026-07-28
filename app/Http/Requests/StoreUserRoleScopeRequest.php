<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRoleScopeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_public_id' => ['required', 'uuid', 'exists:users,public_id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'scope_type' => ['required', 'in:GLOBAL,BRANCH'],
            'branch_public_id' => ['required_if:scope_type,BRANCH', 'nullable', 'uuid', 'exists:branches,public_id'],
        ];
    }
}
