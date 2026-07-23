<?php

namespace App\Modules\Access\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StorePasskeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'clientDataJSON' => ['required', 'string'],
            'attestationObject' => ['required', 'string'],
        ];
    }
}
