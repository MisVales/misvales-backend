<?php

namespace App\Http\Requests\Categoria;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarVersionCategoriaRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'profit_percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'], // Punto 52
            'reason' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
        ];
    }
}
