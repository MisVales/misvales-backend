<?php

namespace App\Http\Requests\Producto;

trait MensajesProductoFinanciero
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'nominal_amount.required' => 'Indica el importe nominal del producto.',
            'nominal_amount.decimal' => 'El importe nominal debe tener como máximo 4 decimales.',
            'nominal_amount.min' => 'El importe nominal debe ser de al menos $100.',
            'nominal_amount.multiple_of' => 'El importe nominal debe ser múltiplo de $100.',
            'loan_commission_percentage.decimal' => 'La comisión debe tener como máximo 6 decimales.',
            'loan_commission_percentage.between' => 'La comisión debe estar entre 0% y 100%.',
            'simple_interest_percentage.decimal' => 'El interés debe tener como máximo 6 decimales.',
            'simple_interest_percentage.between' => 'El interés debe estar entre 0% y 100%.',
            'insurance_amount.decimal' => 'El seguro debe tener como máximo 4 decimales.',
            'insurance_amount.min' => 'El seguro no puede ser negativo.',
            'fortnights_count.integer' => 'El número de quincenas debe ser un número entero.',
            'fortnights_count.min' => 'El número de quincenas debe ser al menos 1.',
            'late_fee_amount.decimal' => 'El recargo debe tener como máximo 4 decimales.',
            'late_fee_amount.min' => 'El recargo no puede ser negativo.',
            'lock_version.required' => 'La versión de edición es obligatoria.',
            'lock_version.integer' => 'La versión de edición no es válida.',
        ];
    }
}
