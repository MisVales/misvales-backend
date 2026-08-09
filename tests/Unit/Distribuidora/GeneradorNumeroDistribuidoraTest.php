<?php

namespace Tests\Unit\Distribuidora;

use App\Services\Distribuidora\GeneradorNumeroDistribuidora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneradorNumeroDistribuidoraTest extends TestCase
{
    use RefreshDatabase;

    public function test_genera_numeros_secuenciales_sin_usar_el_total_de_registros(): void
    {
        $generador = new GeneradorNumeroDistribuidora;

        $primero = $generador->generar(2030);
        $segundo = $generador->generar(2030);

        self::assertMatchesRegularExpression('/^DIS-2030-\d{6,}$/', $primero);
        self::assertSame((int) substr($primero, 9) + 1, (int) substr($segundo, 9));
    }
}
