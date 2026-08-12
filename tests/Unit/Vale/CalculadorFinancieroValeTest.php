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
        self::assertSame('12400.0000', $resultado['client_total']);
        self::assertSame('2975.0000', $resultado['misvales_payment_per_fortnight']);
        self::assertSame('3100.0000', $resultado['client_payment_per_fortnight']);
        self::assertCount(4, $resultado['installments']);
    }

    public function test_ultima_parcialidad_absorbe_residuos_a_cuatro_decimales(): void
    {
        $resultado = (new CalculadorFinancieroVale)->calcular('10000.0000', '0.000000', '0.000000', 3, '0.0000', '0.000000');
        self::assertSame(['3333.3333', '3333.3333', '3333.3334'], array_column($resultado['installments'], 'capital'));
        self::assertSame('10000.0000', array_reduce($resultado['installments'], fn (string $total, array $item): string => bcadd($total, $item['client_payment'], 4), '0.0000'));
    }
}
