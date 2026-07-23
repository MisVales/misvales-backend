<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmTotpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'secret' => ['required', 'string'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
