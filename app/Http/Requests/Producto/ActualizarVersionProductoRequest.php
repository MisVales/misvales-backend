<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarVersionProductoRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'nominal_amount' => ['sometimes', 'required', 'numeric', 'min:100', 'multiple_of:100'], // Punto 59
            'loan_commission_percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'], // Punto 60
            'simple_interest_percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:1'], // Punto 60
            'insurance_amount' => ['sometimes', 'required', 'numeric', 'min:0'], // Punto 61
            'fortnights_count' => ['sometimes', 'required', 'integer', 'min:1'], // Punto 62
            'reason' => ['required', 'string'],
            'lock_version' => ['required', 'integer', 'min:0'],
            'effective_from' => ['sometimes', 'required', 'date', 'after_or_equal:today'],
        ];
    }
}
