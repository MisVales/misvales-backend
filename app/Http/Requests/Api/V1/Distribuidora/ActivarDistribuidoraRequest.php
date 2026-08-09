<?php

namespace App\Http\Requests\Api\V1\Distribuidora;

use Illuminate\Foundation\Http\FormRequest;

class ActivarDistribuidoraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_version_id' => ['required', 'uuid', 'exists:category_versions,id'],
        ];
    }
}
