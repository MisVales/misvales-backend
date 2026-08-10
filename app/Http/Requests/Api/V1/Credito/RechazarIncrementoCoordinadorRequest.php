<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class RechazarIncrementoCoordinadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Se maneja en la Policy
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
