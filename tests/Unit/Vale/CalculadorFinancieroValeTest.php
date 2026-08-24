<?php

namespace Tests\Unit\Vale;

use App\Services\Vale\CalculadorFinancieroVale;
use PHPUnit\Framework\TestCase;

final class CalculadorFinancieroValeTest extends TestCase
{
    public function test_calcula_componentes_sin_confundir_comision_y_ganancia(): void
    {
        $resultado = (new CalculadorFinancieroVale)->calcular('10000.0000', '0.100000', '0.020000', 4, '100.0000', '0.050000');

        self::assertSame('1000.0000', $resultado['loan_commission_amount']);
        self::assertSame('800.0000', $resultado['interest_total']);
        self::assertSame('11900.0000', $resultado['misvales_total']);
        self::assertSame('500.0000', $resultado['distributor_profit_total']);
        self::assertSame('11900.0000', $resultado['client_total']);
        self::assertSame('2975.0000', $resultado['misvales_payment_per_fortnight']);
        self::assertSame('2975.0000', $resultado['client_payment_per_fortnight']);
        self::assertSame('2850.0000', $resultado['net_payment_after_distributor_profit_per_fortnight']);
        self::assertCount(4, $resultado['installments']);
    }

    public function test_parcialidades_se_distribuyen_en_pesos_enteros_y_la_ultima_absorbe_el_residuo(): void
    {
        $resultado = (new CalculadorFinancieroVale)->calcular('10000.0000', '0.000000', '0.000000', 3, '0.0000', '0.000000');
        self::assertSame(['3333.0000', '3333.0000', '3334.0000'], array_column($resultado['installments'], 'capital'));
        self::assertSame('10000.0000', array_reduce($resultado['installments'], fn (string $total, array $item): string => bcadd($total, $item['client_payment'], 4), '0.0000'));
    }

    public function test_aplica_el_desglose_financiero_canonico_sin_confundir_la_ganancia_de_categoria(): void
    {
        $resultado = (new CalculadorFinancieroVale)->calcular('15000.0000', '0.100000', '0.030000', 8, '100.0000', '0.060000');

        self::assertSame('1500.0000', $resultado['loan_commission_amount']);
        self::assertSame('450.0000', $resultado['interest_per_fortnight']);
        self::assertSame('3600.0000', $resultado['interest_total']);
        self::assertSame('20200.0000', $resultado['misvales_total']);
        self::assertSame('2525.0000', $resultado['misvales_payment_per_fortnight']);
        self::assertSame('1875.0000', $resultado['capital_per_fortnight']);
        self::assertSame('900.0000', $resultado['distributor_profit_total']);
        self::assertSame('112.0000', $resultado['distributor_profit_per_fortnight']);
        self::assertSame('2413.0000', $resultado['net_payment_after_distributor_profit_per_fortnight']);
        self::assertSame('2525.0000', $resultado['client_payment_per_fortnight']);
    }

    public function test_aplica_piso_a_importes_con_centavos(): void
    {
        $resultado = (new CalculadorFinancieroVale)->calcular('1000.9000', '0.100000', '0.020000', 2, '100.9000', '0.050000');

        self::assertSame('1000.0000', $resultado['capital']);
        self::assertSame('100.0000', $resultado['insurance_amount']);
        self::assertSame('100.0000', $resultado['loan_commission_amount']);
        self::assertSame('40.0000', $resultado['interest_total']);
        self::assertSame('1240.0000', $resultado['misvales_total']);
        self::assertSame('620.0000', $resultado['misvales_payment_per_fortnight']);
    }
}
