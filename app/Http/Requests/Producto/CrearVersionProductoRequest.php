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
            'nominal_amount' => ['required', 'numeric', 'min:100', 'multiple_of:100'], // Punto 59
            'loan_commission_percentage' => ['required', 'numeric', 'min:0', 'max:1'], // Punto 60
            'simple_interest_percentage' => ['required', 'numeric', 'min:0', 'max:1'], // Punto 60
            'insurance_amount' => ['required', 'numeric', 'min:0'], // Punto 61
            'fortnights_count' => ['required', 'integer', 'min:1'], // Punto 62
            'reason' => ['required', 'string'],
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
