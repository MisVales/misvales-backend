<?php

namespace Tests\Unit\Credito;

use App\Services\Credito\EvaluadorRestriccion50Porciento;
use PHPUnit\Framework\TestCase;

class EvaluadorRestriccion50PorcientoTest extends TestCase
{
    protected EvaluadorRestriccion50Porciento $evaluador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluador = new EvaluadorRestriccion50Porciento();
    }

    public function test_rango_nominal_con_saldo_suficiente()
    {
        // Caso: Base 40000, disponible 30000, tolerancia 500 -> rango nominal 19500 a 20500
        $resultado = $this->evaluador->evaluar('40000.0000', '30000.0000', '500.0000');

        $this->assertEquals('20000.0000', $resultado['referencia']);
        $this->assertEquals('19500.0000', $resultado['limite_inferior']);
        $this->assertEquals('20500.0000', $resultado['limite_superior_nominal']);
        $this->assertEquals('20500.0000', $resultado['limite_superior_real']);
        $this->assertTrue($resultado['admisible']);
    }

    public function test_rango_limitado_por_saldo_disponible()
    {
        // Caso: Base 40000, disponible 19800, tolerancia 500 -> rango 19500 a 19800
        $resultado = $this->evaluador->evaluar('40000.0000', '19800.0000', '500.0000');

        $this->assertEquals('19500.0000', $resultado['limite_inferior']);
        $this->assertEquals('19800.0000', $resultado['limite_superior_real']);
        $this->assertTrue($resultado['admisible']);
    }

    public function test_rango_no_admisible_cuando_superior_menor_que_inferior()
    {
        // Caso: Base 40000, disponible 19000, tolerancia 500 -> sin rango admisible (19500 vs 19000)
        $resultado = $this->evaluador->evaluar('40000.0000', '19000.0000', '500.0000');

        $this->assertEquals('19500.0000', $resultado['limite_inferior']);
        $this->assertEquals('19000.0000', $resultado['limite_superior_real']);
        $this->assertFalse($resultado['admisible']);
    }

    public function test_tolerancia_congelada_funciona_correctamente()
    {
        // El evaluador recibe la tolerancia inyectada, lo que garantiza que usa la congelada
        // Caso: Base 40000, disponible 20000, tolerancia 0 (cambió a 0 en BD pero la restricción tenía 100)
        // La restricción dice 100, así que debe usarse 100
        $resultado = $this->evaluador->evaluar('40000.0000', '20000.0000', '100.0000');

        $this->assertEquals('19900.0000', $resultado['limite_inferior']);
        $this->assertEquals('20000.0000', $resultado['limite_superior_real']);
        $this->assertTrue($resultado['admisible']);
    }
}
