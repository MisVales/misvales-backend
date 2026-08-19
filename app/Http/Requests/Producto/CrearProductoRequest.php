<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;

class CrearProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:255', 'unique:products,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'nominal_amount' => ['required', 'numeric', 'min:100', 'multiple_of:100'],
            'loan_commission_percentage' => ['required', 'numeric', 'between:0,1'],
            'simple_interest_percentage' => ['required', 'numeric', 'between:0,1'],
            'insurance_amount' => ['required', 'numeric', 'min:0'],
            'fortnights_count' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string'],
        ];
    }
}
