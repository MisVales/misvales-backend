<?php

namespace Tests\Unit\Credito;

use App\Services\Credito\CalculadorSaldoCredito;
use PHPUnit\Framework\TestCase;

class CalculadorSaldoCreditoTest extends TestCase
{
    protected CalculadorSaldoCredito $calculador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculador = new CalculadorSaldoCredito;
    }

    public function test_calcula_saldo_disponible_correctamente()
    {
        // Caso: Línea 30000, utilizada 12000 -> disponible 18000
        $resultado = $this->calculador->calcular('30000.0000', '12000.0000');

        $this->assertEquals('30000.0000', $resultado['total_authorized']);
        $this->assertEquals('12000.0000', $resultado['used_balance']);
        $this->assertEquals('18000.0000', $resultado['available_balance']);
    }

    public function test_calcula_nuevo_saldo_disponible()
    {
        // Caso: Nueva línea 40000, utilizada 12000 -> disponible 28000
        $resultado = $this->calculador->calcular('40000.0000', '12000.0000');

        $this->assertEquals('40000.0000', $resultado['total_authorized']);
        $this->assertEquals('12000.0000', $resultado['used_balance']);
        $this->assertEquals('28000.0000', $resultado['available_balance']);
    }

    public function test_rechaza_cuando_utilizado_supera_autorizado()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Estado inconsistente: used_balance no puede ser mayor a total_authorized.');

        $this->calculador->calcular('10000.0000', '12000.0000');
    }
}
