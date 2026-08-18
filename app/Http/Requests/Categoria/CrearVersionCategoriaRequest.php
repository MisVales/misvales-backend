<?php

namespace App\Http\Requests\Categoria;

use Illuminate\Foundation\Http\FormRequest;

class CrearVersionCategoriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'profit_percentage' => ['required', 'numeric', 'min:0', 'max:1'], // Punto 52
            'reason' => ['required', 'string'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
