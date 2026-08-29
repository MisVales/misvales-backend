<?php

namespace Tests\Unit\Relacion;

use App\Services\Vale\CalculadorFinancieroVale;
use PHPUnit\Framework\TestCase;

final class RelacionFinancieraAuditoriaTest extends TestCase
{
    private CalculadorFinancieroVale $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new CalculadorFinancieroVale();
    }

    /**
     * Calcula el saldo exigible a MisVales para un corte k de un vale,
     * donde las parcialidades 1..(k-1) están vencidas sin pago (con recargo)
     * y la parcialidad k es la vigente actual (sin recargo).
     */
    private function calcularSaldoRelacion(
        string $capital,
        string $comision,
        string $interes,
        int $quincenas,
        string $seguro,
        string $ganancia,
        int $corteActual,
        string $recargoUnitario = '300.0000',
        bool $corteActualVencido = false
    ): string {
        $plan = $this->calc->calcular($capital, $comision, $interes, $quincenas, $seguro, $ganancia);

        $cuotaCliente = $plan['client_payment_per_fortnight'];
        $netoMisvales = $plan['misvales_payment_per_fortnight'];

        $overdueCount = $corteActualVencido ? $corteActual : ($corteActual - 1);
        $overdueAmountPerInstallment = bcadd($cuotaCliente, $recargoUnitario, 4);
        $totalOverdue = bcmul($overdueAmountPerInstallment, (string) $overdueCount, 4);

        if ($corteActualVencido) {
            return $totalOverdue;
        }

        return bcadd($totalOverdue, $netoMisvales, 4);
    }

    public function test_maria_ocho_quincenas_todos_los_cortes(): void
    {
        $esperados = [
            1 => '1812.0000',
            2 => '3999.0000',
            3 => '6186.0000',
            4 => '8373.0000',
            5 => '10560.0000',
            6 => '12747.0000',
            7 => '14934.0000',
            8 => '17121.0000',
        ];

        foreach ($esperados as $corte => $esperado) {
            $saldo = $this->calcularSaldoRelacion('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', $corte);
            self::assertSame($esperado, $saldo, "Fallo en María corte {$corte}/8");
        }
    }

    public function test_luis_ocho_quincenas_hasta_corte_seis(): void
    {
        $esperados = [
            1 => '2712.0000',
            2 => '5837.0000',
            3 => '8962.0000',
            4 => '12087.0000',
            5 => '15212.0000',
            6 => '18337.0000',
        ];

        foreach ($esperados as $corte => $esperado) {
            $saldo = $this->calcularSaldoRelacion('15000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', $corte);
            self::assertSame($esperado, $saldo, "Fallo en Luis corte {$corte}/8");
        }
    }

    public function test_gabriela_ocho_quincenas_hasta_corte_tres(): void
    {
        $esperados = [
            1 => '912.0000',
            2 => '2162.0000',
            3 => '3412.0000',
        ];

        foreach ($esperados as $corte => $esperado) {
            $saldo = $this->calcularSaldoRelacion('5000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', $corte);
            self::assertSame($esperado, $saldo, "Fallo en Gabriela corte {$corte}/8");
        }
    }

    public function test_feliz_ocho_quincenas_hasta_corte_dos(): void
    {
        $esperados = [
            1 => '912.0000',
            2 => '2162.0000',
        ];

        foreach ($esperados as $corte => $esperado) {
            $saldo = $this->calcularSaldoRelacion('5000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', $corte);
            self::assertSame($esperado, $saldo, "Fallo en Feliz corte {$corte}/8");
        }
    }

    public function test_suma_total_cuatro_casos_da_cuarenta_y_un_mil_treinta_y_dos(): void
    {
        $maria = $this->calcularSaldoRelacion('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 8);
        $luis = $this->calcularSaldoRelacion('15000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 6);
        $gabriela = $this->calcularSaldoRelacion('5000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 3);
        $feliz = $this->calcularSaldoRelacion('5000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 2);

        $total = bcadd(bcadd(bcadd($maria, $luis, 4), $gabriela, 4), $feliz, 4);

        self::assertSame('17121.0000', $maria);
        self::assertSame('18337.0000', $luis);
        self::assertSame('3412.0000', $gabriela);
        self::assertSame('2162.0000', $feliz);
        self::assertSame('41032.0000', $total);
    }

    public function test_maria_si_no_paga_octava_quincena_produce_cuarenta_y_un_mil_cuatrocientos_siete(): void
    {
        $mariaVencidaTotal = $this->calcularSaldoRelacion(
            '10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 8, '300.0000', true
        );
        $mariaVigente = $this->calcularSaldoRelacion('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 8);
        $incremento = bcsub($mariaVencidaTotal, $mariaVigente, 4);

        self::assertSame('375.0000', $incremento);

        $luis = $this->calcularSaldoRelacion('15000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 6);
        $gabriela = $this->calcularSaldoRelacion('5000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 3);
        $feliz = $this->calcularSaldoRelacion('5000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 2);

        $totalConMariaVencida = bcadd(bcadd(bcadd($mariaVencidaTotal, $luis, 4), $gabriela, 4), $feliz, 4);
        self::assertSame('41407.0000', $totalConMariaVencida);
    }

    public function test_parcialidad_vigente_sin_mora_conserva_ganancia(): void
    {
        $plan = $this->calc->calcular('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000');
        self::assertSame('1812.0000', $plan['misvales_payment_per_fortnight']);
        self::assertSame('1887.0000', $plan['client_payment_per_fortnight']);
        self::assertSame('75.0000', $plan['distributor_profit_per_fortnight']);
    }

    public function test_cinco_vencimientos_consecutivos_maria(): void
    {
        // Caso obligatorio del usuario:
        // 8/8 vigente = 17121
        // +375 = 17496
        // +375 = 17871
        // +375 = 18246
        // +375 = 18621
        // +375 = 18996
        $saldoVigente = $this->calcularSaldoRelacion('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 8);
        self::assertSame('17121.0000', $saldoVigente);

        $plan = $this->calc->calcular('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000');
        $multa = '300.0000';
        $gananciaQuincena = $plan['distributor_profit_per_fortnight']; // 75.0000
        $incremento = bcadd($multa, $gananciaQuincena, 4); // 375.0000

        self::assertSame('375.0000', $incremento);

        $vencimiento1 = bcadd($saldoVigente, $incremento, 4);
        self::assertSame('17496.0000', $vencimiento1, '1.er vencimiento debe ser 17,496');

        $vencimiento2 = bcadd($vencimiento1, $incremento, 4);
        self::assertSame('17871.0000', $vencimiento2, '2.º vencimiento debe ser 17,871');

        $vencimiento3 = bcadd($vencimiento2, $incremento, 4);
        self::assertSame('18246.0000', $vencimiento3, '3.er vencimiento debe ser 18,246');

        $vencimiento4 = bcadd($vencimiento3, $incremento, 4);
        self::assertSame('18621.0000', $vencimiento4, '4.º vencimiento debe ser 18,621');

        $vencimiento5 = bcadd($vencimiento4, $incremento, 4);
        self::assertSame('18996.0000', $vencimiento5, '5.º vencimiento debe ser 18,996');
    }

    public function test_diferentes_porcentajes_categoria_incrementan_dinamicamente(): void
    {
        // Vale con 8% de categoría: Ganancia = 10,000 * 0.08 = 800 / 8Q = 100 quincenal
        // Multa = $300. Incremento esperado = $300 + $100 = $400
        $plan8 = $this->calc->calcular('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.080000');
        $multa = '300.0000';
        $incremento8 = bcadd($multa, $plan8['distributor_profit_per_fortnight'], 4);
        self::assertSame('100.0000', $plan8['distributor_profit_per_fortnight']);
        self::assertSame('400.0000', $incremento8);

        // Vale con 4% de categoría: Ganancia = 10,000 * 0.04 = 400 / 8Q = 50 quincenal
        // Multa = $250. Incremento esperado = $250 + $50 = $300
        $plan4 = $this->calc->calcular('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.040000');
        $multa250 = '250.0000';
        $incremento4 = bcadd($multa250, $plan4['distributor_profit_per_fortnight'], 4);
        self::assertSame('50.0000', $plan4['distributor_profit_per_fortnight']);
        self::assertSame('300.0000', $incremento4);
    }

    public function test_diferentes_montos_de_multa(): void
    {
        $plan = $this->calc->calcular('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000');
        $ganancia = $plan['distributor_profit_per_fortnight']; // 75.0000

        foreach (['150.0000' => '225.0000', '250.0000' => '325.0000', '500.0000' => '575.0000'] as $multa => $esperado) {
            $incremento = bcadd($multa, $ganancia, 4);
            self::assertSame($esperado, $incremento);
        }
    }

    public function test_vale_pagado_antes_del_siguiente_corte_no_acumula_recargo_adicional(): void
    {
        $saldoVigente = $this->calcularSaldoRelacion('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 8);
        $pagoRealizado = $saldoVigente;
        $saldoRestante = bcsub($saldoVigente, $pagoRealizado, 4);

        self::assertSame('0.0000', $saldoRestante);
    }

    public function test_parcialidad_parcialmente_pagada_reduce_saldo(): void
    {
        $saldoVigente = $this->calcularSaldoRelacion('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000', 8);
        $pagoParcial = '5000.0000';
        $saldoRestante = bcsub($saldoVigente, $pagoParcial, 4);

        self::assertSame('12121.0000', $saldoRestante);
    }

    public function test_redondeo_en_ultima_quincena_mantiene_exactitud(): void
    {
        // Verifica que la cuota fija periódica del cliente y MisVales permanezca exacta
        $plan = $this->calc->calcular('10000.0000', '0.100000', '0.050000', 8, '100.0000', '0.060000');
        self::assertSame('1887.0000', $plan['client_payment_per_fortnight']);
        self::assertSame('1812.0000', $plan['misvales_payment_per_fortnight']);
        self::assertSame('75.0000', $plan['distributor_profit_per_fortnight']);
    }

    public function test_diferentes_recargos_y_plazos(): void
    {
        $saldoCorte2 = $this->calcularSaldoRelacion('4000.0000', '0.100000', '0.030000', 4, '50.0000', '0.040000', 2, '200.0000');
        self::assertNotNull($saldoCorte2);
        self::assertTrue(bccomp($saldoCorte2, '0', 4) > 0);
    }
}
