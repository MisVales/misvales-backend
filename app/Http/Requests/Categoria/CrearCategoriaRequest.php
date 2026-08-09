<?php

namespace App\Http\Requests\Categoria;

use Illuminate\Foundation\Http\FormRequest;

class CrearCategoriaRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', 'unique:categories,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'profit_percentage' => ['required', 'numeric', 'min:0', 'max:1'], // Punto 52
            'reason' => ['required', 'string'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
