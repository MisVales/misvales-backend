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

    public function test_diferentes_recargos_y_plazos(): void
    {
        $saldoCorte2 = $this->calcularSaldoRelacion('4000.0000', '0.100000', '0.030000', 4, '50.0000', '0.040000', 2, '200.0000');
        self::assertNotNull($saldoCorte2);
        self::assertTrue(bccomp($saldoCorte2, '0', 4) > 0);
    }
}
