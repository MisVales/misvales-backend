<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use App\Modules\Access\Domain\MFA\MfaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'exchange_token' => ['required', 'string', 'min:32', 'max:512'],
            'recovery_codes_confirmed' => ['sometimes', 'boolean'],
            'password' => ['required_unless:recovery_codes_confirmed,true', 'string', 'min:12', 'max:128', 'confirmed'],
            'password_confirmation' => ['required_unless:recovery_codes_confirmed,true', 'string', 'max:128'],
            'mfa' => ['required_unless:recovery_codes_confirmed,true', 'array'],
            'mfa.type' => ['required_with:mfa', Rule::enum(MfaType::class)],
            'mfa.secret' => ['required_if:mfa.type,TOTP', 'string', 'max:256'],
            'mfa.code' => ['required_if:mfa.type,TOTP', 'digits:6'],
            'mfa.credential_identifier' => ['required_if:mfa.type,PASSKEY', 'string', 'max:255'],
            'mfa.public_key' => ['required_if:mfa.type,PASSKEY', 'string', 'max:16000'],
            'mfa.attestation_token' => ['required_if:mfa.type,PASSKEY', 'string', 'size:64'],
        ];
    }
}
