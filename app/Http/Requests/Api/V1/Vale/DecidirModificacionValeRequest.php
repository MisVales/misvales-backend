<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;

final class DecidirModificacionValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:AUTHORIZE,REJECT'], 'lock_version' => ['required', 'integer', 'min:1']];
    }
}
