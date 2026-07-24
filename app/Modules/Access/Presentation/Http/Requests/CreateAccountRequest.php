<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\RoleCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::enum(RoleCode::class)],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,public_id'],
            'authorization_token' => ['required', 'string', 'min:32', 'max:512'],
            'password' => ['prohibited'],
        ];
    }
}
