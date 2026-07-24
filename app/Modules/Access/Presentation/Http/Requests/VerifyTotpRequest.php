<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyTotpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'mfa_token' => ['required', 'string'], // El token temporal de 5 minutos devuelto por /auth/login
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
