<?php

namespace App\Http\Requests\Api\V1\Vale;

use Illuminate\Foundation\Http\FormRequest;

final class LiberarValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'matches' => ['required', 'accepted'],
        ];
    }
}
