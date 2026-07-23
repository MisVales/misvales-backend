<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompletePasswordRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:32', 'max:512'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'max:128'],
            'factor_type' => ['nullable', Rule::in(['TOTP', 'RECOVERY_CODE', 'PASSKEY_AUTHORIZATION'])],
            'factor_value' => ['nullable', 'string', 'max:512'],
            'mfa' => ['nullable', 'array'],
            'mfa.type' => ['required_with:mfa', Rule::in(['TOTP', 'PASSKEY'])],
            'mfa.secret' => ['required_if:mfa.type,TOTP', 'string', 'max:256'],
            'mfa.code' => ['required_if:mfa.type,TOTP', 'digits:6'],
            'mfa.credential_identifier' => ['required_if:mfa.type,PASSKEY', 'string', 'max:255'],
            'mfa.public_key' => ['required_if:mfa.type,PASSKEY', 'string', 'max:16000'],
            'mfa.attestation_token' => ['required_if:mfa.type,PASSKEY', 'string', 'size:64'],
        ];
    }
}
