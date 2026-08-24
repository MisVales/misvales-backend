<?php

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

class AsignarCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_version_id' => ['required', 'uuid', 'exists:category_versions,id'],
            'starts_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
