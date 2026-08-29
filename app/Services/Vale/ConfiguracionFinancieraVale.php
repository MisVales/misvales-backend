<?php

namespace App\Services\Vale;

use App\Exceptions\ExcepcionVale;
use App\Models\Product;
use App\Models\ProductVersion;

final class ConfiguracionFinancieraVale
{
    /**
     * Resuelve las condiciones financieras directamente desde el modelo Product.
     *
     * @return array{
     *     values: array{loan_commission_percentage: string, simple_interest_percentage: string, insurance_amount: string, fortnights_count: int, late_fee_amount: string},
     * }
     */
    public function resolver(Product|ProductVersion $product): array
    {
        $faltantes = [];

        if (is_null($product->loan_commission_percentage)) {
            $faltantes[] = 'comisión del préstamo';
        }
        if (is_null($product->simple_interest_percentage)) {
            $faltantes[] = 'interés por quincena';
        }
        if (is_null($product->insurance_amount)) {
            $faltantes[] = 'seguro del vale';
        }
        if (is_null($product->fortnights_count)) {
            $faltantes[] = 'número de quincenas';
        }
        if (is_null($product->late_fee_amount)) {
            $faltantes[] = 'recargo por falta de pago';
        }

        if ($faltantes !== []) {
            throw new ExcepcionVale(
                'VOUCHER_FINANCIAL_CONFIGURATION_MISSING',
                'Aún no se pueden otorgar vales con este producto: falta configurar la '.implode(', ', $faltantes).'.',
                409,
                ['missing' => array_values($faltantes)],
            );
        }

        try {
            $values = [
                'loan_commission_percentage' => $this->porcentaje((string) $product->loan_commission_percentage),
                'simple_interest_percentage' => $this->porcentaje((string) $product->simple_interest_percentage),
                'insurance_amount' => $this->monto((string) $product->insurance_amount),
                'fortnights_count' => $this->enteroPositivo((string) $product->fortnights_count),
                'late_fee_amount' => $this->monto((string) $product->late_fee_amount),
            ];
        } catch (\InvalidArgumentException) {
            throw new ExcepcionVale(
                'VOUCHER_FINANCIAL_CONFIGURATION_INVALID',
                'Una condición financiera del producto tiene un valor inválido.',
                409,
            );
        }

        return ['values' => $values];
    }

    private function porcentaje(string $valor): string
    {
        if (! is_numeric($valor) || bccomp($valor, '0', 6) < 0 || bccomp($valor, '1', 6) > 0) {
            throw new \InvalidArgumentException('Porcentaje inválido.');
        }

        return bcadd($valor, '0', 6);
    }

    private function monto(string $valor): string
    {
        if (! is_numeric($valor) || bccomp($valor, '0', 4) < 0) {
            throw new \InvalidArgumentException('Monto inválido.');
        }

        return bcadd($valor, '0', 4);
    }

    private function enteroPositivo(string $valor): int
    {
        if (filter_var($valor, FILTER_VALIDATE_INT) === false || (int) $valor < 1) {
            throw new \InvalidArgumentException('Número de quincenas inválido.');
        }

        return (int) $valor;
    }
}
