<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;

class CrearVersionProductoRequest extends FormRequest
{
    use MensajesProductoFinanciero;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'nominal_amount' => ['required', 'decimal:0,4', 'min:100', 'multiple_of:100'],
            'reason' => ['required', 'string'],
            'loan_commission_percentage' => ['sometimes', 'nullable', 'decimal:0,6', 'between:0,1'],
            'simple_interest_percentage' => ['sometimes', 'nullable', 'decimal:0,6', 'between:0,1'],
            'insurance_amount' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0'],
            'fortnights_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'late_fee_amount' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0'],
        ];
    }
}
