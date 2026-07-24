<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LifecycleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('api.v1.account-requests.*')) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'reauth_token' => ['nullable', 'string', 'min:32', 'max:512'],
            'compromise' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'required', 'string', 'max:100'],
            'password' => ['prohibited'],
        ];
    }
}
