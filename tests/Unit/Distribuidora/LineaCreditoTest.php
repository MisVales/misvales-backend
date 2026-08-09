<?php

namespace Tests\Unit\Distribuidora;

use App\Models\LineaCredito;
use PHPUnit\Framework\TestCase;

class LineaCreditoTest extends TestCase
{
    public function test_calcula_saldo_disponible_sin_perder_precision_decimal(): void
    {
        $linea = new LineaCredito;
        $linea->total_authorized = '10000.0000';
        $linea->used_balance = '1250.5555';

        self::assertSame('8749.4445', $linea->saldoDisponible());
    }

    public function test_importes_se_conservan_con_cuatro_decimales(): void
    {
        $linea = new LineaCredito;
        $linea->total_authorized = '15000';
        $linea->used_balance = '0';

        self::assertSame('15000.0000', $linea->total_authorized);
        self::assertSame('0.0000', $linea->used_balance);
    }
}
