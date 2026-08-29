<?php

namespace Tests\Unit\Vale;

use App\Exceptions\ExcepcionVale;
use App\Models\Product;
use App\Services\Vale\ConfiguracionFinancieraVale;
use PHPUnit\Framework\TestCase;

final class ConfiguracionFinancieraValeTest extends TestCase
{
    public function test_resuelve_y_normaliza_las_condiciones_del_producto(): void
    {
        $producto = new Product([
            'loan_commission_percentage' => '0.1',
            'simple_interest_percentage' => '0.05',
            'insurance_amount' => '100',
            'fortnights_count' => 8,
            'late_fee_amount' => '200',
        ]);

        $resultado = (new ConfiguracionFinancieraVale)->resolver($producto);

        self::assertSame([
            'loan_commission_percentage' => '0.100000',
            'simple_interest_percentage' => '0.050000',
            'insurance_amount' => '100.0000',
            'fortnights_count' => 8,
            'late_fee_amount' => '200.0000',
        ], $resultado['values']);
    }

    public function test_rechaza_la_emision_cuando_al_producto_le_falta_una_condicion(): void
    {
        $producto = new Product([
            'loan_commission_percentage' => '0.1',
            'simple_interest_percentage' => '0.05',
            'fortnights_count' => 8,
            'late_fee_amount' => '200',
        ]);

        try {
            (new ConfiguracionFinancieraVale)->resolver($producto);
            self::fail('Se esperaba una excepción de configuración faltante.');
        } catch (ExcepcionVale $exception) {
            self::assertSame('VOUCHER_FINANCIAL_CONFIGURATION_MISSING', $exception->errorCode);
            self::assertSame(['seguro del vale'], $exception->details['missing']);
        }
    }

    public function test_rechaza_un_valor_financiero_invalido_del_producto(): void
    {
        $producto = new Product([
            'loan_commission_percentage' => '1.1',
            'simple_interest_percentage' => '0.05',
            'insurance_amount' => '100',
            'fortnights_count' => 8,
            'late_fee_amount' => '200',
        ]);

        try {
            (new ConfiguracionFinancieraVale)->resolver($producto);
            self::fail('Se esperaba una excepción por valor financiero inválido.');
        } catch (ExcepcionVale $exception) {
            self::assertSame('VOUCHER_FINANCIAL_CONFIGURATION_INVALID', $exception->errorCode);
        }
    }
}
