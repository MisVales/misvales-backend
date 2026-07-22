<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\RoleCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchAccountRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

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
            'role' => ['required', Rule::in([RoleCode::COORDINATOR->value, RoleCode::VERIFIER->value, RoleCode::CASHIER->value])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'reauth_token' => ['required', 'string', 'min:32', 'max:512'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'branch_id' => ['prohibited'],
            'password' => ['prohibited'],
        ];
    }
}
