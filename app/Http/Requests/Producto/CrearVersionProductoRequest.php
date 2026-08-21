<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;

class CrearVersionProductoRequest extends FormRequest
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
            'nominal_amount' => ['required', 'numeric', 'min:100', 'multiple_of:100'],
            'reason' => ['required', 'string'],
        ];
    }
}
