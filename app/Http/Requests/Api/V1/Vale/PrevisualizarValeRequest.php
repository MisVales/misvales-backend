<?php

namespace App\Http\Requests\Api\V1\Vale;

use App\Exceptions\ApiException;
use App\Models\Vale;
use Illuminate\Foundation\Http\FormRequest;

final class PrevisualizarValeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $financialFields = [
            'commission_rate',
            'loan_commission_percentage',
            'interest_rate',
            'simple_interest_percentage',
            'insurance_amount',
            'installment_count',
            'fortnights_count',
            'minimum_installment_count',
            'maximum_installment_count',
            'late_fee_amount',
            'category_rate',
            'distributor_profit_percentage',
        ];
        $sent = array_values(array_filter($financialFields, fn (string $field): bool => $this->exists($field)));
        if ($sent !== []) {
            $messages = [
                'commission_rate' => 'La comisión se define en el producto seleccionado.',
                'loan_commission_percentage' => 'La comisión se define en el producto seleccionado.',
                'interest_rate' => 'El interés se define en el producto seleccionado.',
                'simple_interest_percentage' => 'El interés se define en el producto seleccionado.',
                'insurance_amount' => 'El seguro se define en el producto seleccionado.',
                'installment_count' => 'Las quincenas se definen en el producto seleccionado.',
                'fortnights_count' => 'Las quincenas se definen en el producto seleccionado.',
                'minimum_installment_count' => 'El mínimo de quincenas ya no se captura al solicitar el vale; se define en el producto.',
                'maximum_installment_count' => 'El máximo de quincenas ya no se captura al solicitar el vale; se define en el producto.',
                'late_fee_amount' => 'El recargo se define en el producto seleccionado.',
                'category_rate' => 'La tasa de categoría se determina en el servidor.',
                'distributor_profit_percentage' => 'La ganancia de la distribuidora se determina en el servidor.',
            ];
            throw new ApiException(
                'VOUCHER_FINANCIAL_INPUT_FORBIDDEN',
                'Las condiciones financieras y las quincenas se determinan exclusivamente desde el producto publicado.',
                422,
                array_combine($sent, array_map(
                    static fn (string $field): array => [$messages[$field] ?? 'Elimina este campo: el servidor toma el valor vigente del producto.'],
                    $sent,
                )),
            );
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Vale::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],
            'product_version_id' => ['required', 'uuid'],
            'commission_rate' => ['prohibited'],
            'loan_commission_percentage' => ['prohibited'],
            'interest_rate' => ['prohibited'],
            'simple_interest_percentage' => ['prohibited'],
            'insurance_amount' => ['prohibited'],
            'installment_count' => ['prohibited'],
            'fortnights_count' => ['prohibited'],
            'minimum_installment_count' => ['prohibited'],
            'maximum_installment_count' => ['prohibited'],
            'late_fee_amount' => ['prohibited'],
            'category_rate' => ['prohibited'],
            'distributor_profit_percentage' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'commission_rate.prohibited' => 'La comisión se define en el producto seleccionado.',
            'interest_rate.prohibited' => 'El interés se define en el producto seleccionado.',
            'insurance_amount.prohibited' => 'El seguro se define en el producto seleccionado.',
            'installment_count.prohibited' => 'Las quincenas se definen en el producto seleccionado.',
            'fortnights_count.prohibited' => 'Las quincenas se definen en el producto seleccionado.',
            'minimum_installment_count.prohibited' => 'El mínimo de quincenas se define en el producto seleccionado.',
            'maximum_installment_count.prohibited' => 'El máximo de quincenas se define en el producto seleccionado.',
            'late_fee_amount.prohibited' => 'El recargo se define en el producto seleccionado.',
            'category_rate.prohibited' => 'La tasa de categoría se determina en el servidor.',
            'distributor_profit_percentage.prohibited' => 'La ganancia de la distribuidora se determina en el servidor.',
        ];
    }
}
