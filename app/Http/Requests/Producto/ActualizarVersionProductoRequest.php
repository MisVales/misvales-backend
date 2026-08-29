<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;

class ActualizarVersionProductoRequest extends FormRequest
{
    use MensajesProductoFinanciero;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'nominal_amount' => ['sometimes', 'required', 'decimal:0,4', 'min:100', 'multiple_of:100'],
            'loan_commission_percentage' => ['sometimes', 'nullable', 'decimal:0,6', 'between:0,1'],
            'simple_interest_percentage' => ['sometimes', 'nullable', 'decimal:0,6', 'between:0,1'],
            'insurance_amount' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0'],
            'fortnights_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'late_fee_amount' => ['sometimes', 'nullable', 'decimal:0,4', 'min:0'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
