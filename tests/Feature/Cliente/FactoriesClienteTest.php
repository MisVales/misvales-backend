<?php

namespace Tests\Feature\Cliente;

use App\Models\AsignacionClienteDistribuidora;
use App\Models\Cliente;
use App\Models\CuentaBancariaCliente;
use App\Models\DomicilioCliente;
use App\Models\MovimientoCarteraCliente;
use App\Services\Cliente\GeneradorNumeroCliente;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FactoriesClienteTest extends TestCase
{
    use DatabaseTransactions;

    public function test_factories_generan_registros_sinteticos_compatibles_con_mariadb(): void
    {
        $cliente = Cliente::factory()->create();
        $domicilio = DomicilioCliente::factory()->for($cliente, 'cliente')->create();
        $cuenta = CuentaBancariaCliente::factory()->for($cliente, 'cliente')->create();
        $asignacion = AsignacionClienteDistribuidora::factory()->for($cliente, 'cliente')->create();
        $movimiento = MovimientoCarteraCliente::factory()->for($cliente, 'cliente')->create();

        self::assertNotSame($cliente->curp_ciphertext, $cliente->curp_hmac);
        self::assertSame(64, strlen($cliente->curp_hmac));
        self::assertSame(64, strlen($domicilio->normalized_fingerprint_hmac));
        self::assertNotNull($cuenta->clabe_ciphertext);
        self::assertSame($cliente->id, $asignacion->client_id);
        self::assertSame($cliente->id, $movimiento->client_id);
    }

    public function test_generador_de_numero_cliente_usa_secuencia_y_no_el_total_de_registros(): void
    {
        $generador = app(GeneradorNumeroCliente::class);
        $primero = $generador->generar(2026);
        $segundo = $generador->generar(2026);

        self::assertMatchesRegularExpression('/^CLI-2026-\d{6,}$/', $primero);
        self::assertNotSame($primero, $segundo);
    }
}
