<?php

namespace Tests\Unit\Cliente;

use App\Models\Cliente;
use App\Models\CuentaBancariaCliente;
use App\Models\DomicilioCliente;
use PHPUnit\Framework\TestCase;

class ModelosClienteTest extends TestCase
{
    public function test_cliente_oculta_campos_sensibles_y_no_los_expone_a_asignacion_masiva(): void
    {
        $cliente = new Cliente;

        self::assertContains('curp_ciphertext', $cliente->getHidden());
        self::assertContains('curp_hmac', $cliente->getHidden());
        self::assertNotContains('curp_ciphertext', $cliente->getFillable());
        self::assertNotContains('official_id_number_ciphertext', $cliente->getFillable());
    }

    public function test_domicilio_oculta_huella(): void
    {
        self::assertContains('normalized_fingerprint_hmac', (new DomicilioCliente)->getHidden());
    }

    public function test_cuenta_oculta_cifrados_y_hmacs(): void
    {
        $ocultos = (new CuentaBancariaCliente)->getHidden();

        self::assertContains('account_number_ciphertext', $ocultos);
        self::assertContains('clabe_ciphertext', $ocultos);
        self::assertContains('clabe_hmac', $ocultos);
    }
}
