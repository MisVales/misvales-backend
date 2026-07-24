<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\ReauthenticationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReauthenticateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'method' => ['required', Rule::enum(ReauthenticationMethod::class)],
            'action' => ['required', Rule::enum(CriticalAction::class)],
            'resource_type' => ['nullable', 'string', 'max:128'],
            'resource_id' => ['nullable', 'string', 'max:128'],
            'branch_id' => ['nullable', 'uuid'],
            'parameters' => ['sometimes', 'array', 'max:100'],
            'reason' => ['nullable', 'string', 'min:3', 'max:1000'],
            'password' => ['required_if:method,'.ReauthenticationMethod::PASSWORD_TOTP->value, 'string', 'max:128'],
            'totp_code' => ['required_if:method,'.ReauthenticationMethod::PASSWORD_TOTP->value, 'string', 'regex:/^\d{6}$/'],
            'challenge_id' => ['nullable', 'string', 'size:64'],
            'assertion' => ['nullable', 'array'],
            'assertion.id' => ['required_with:assertion', 'string', 'max:2048'],
            'assertion.rawId' => ['required_with:assertion', 'string', 'max:2048'],
            'assertion.type' => ['required_with:assertion', 'string', 'in:public-key'],
            'assertion.response.clientDataJSON' => ['required_with:assertion', 'string', 'max:16384'],
            'assertion.response.authenticatorData' => ['required_with:assertion', 'string', 'max:16384'],
            'assertion.response.signature' => ['required_with:assertion', 'string', 'max:16384'],
            'assertion.response.userHandle' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
