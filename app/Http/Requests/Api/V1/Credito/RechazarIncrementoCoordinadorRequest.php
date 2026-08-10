<?php

namespace App\Http\Requests\Api\V1\Credito;

use Illuminate\Foundation\Http\FormRequest;

class RechazarIncrementoCoordinadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notas' => ['nullable', 'string', 'max:500'],
        ];
    }
}
