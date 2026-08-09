<?php

namespace Tests\Unit\Cliente;

use App\Services\Cliente\GeneradorHuellaDomicilio;
use App\Services\Cliente\NormalizadorCurp;
use App\Services\Cliente\NormalizadorDomicilio;
use App\Services\Cliente\ProtectorDatosCliente;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Tests\TestCase;

class NormalizacionYProteccionClienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['clientes.hmac_key' => 'clave-hmac-de-prueba-distinta']);
    }

    public function test_normaliza_y_valida_curp(): void
    {
        $servicio = new NormalizadorCurp;

        self::assertSame('LOGM900101MCLPRR01', $servicio->normalizar(' logm-900101-mclprr01 '));
    }

    public function test_rechaza_curp_invalida(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NormalizadorCurp)->normalizar('CURP INVALIDA');
    }

    public function test_normaliza_acentos_espacios_y_mayusculas_del_domicilio(): void
    {
        $normalizado = (new NormalizadorDomicilio)->normalizar($this->domicilio([
            'street' => '  Avenida   Constitución ',
            'city' => 'torreón',
        ]));

        self::assertSame('AVENIDA CONSTITUCION', $normalizado['street']);
        self::assertSame('TORREON', $normalizado['city']);
        self::assertSame('MX', $normalizado['country']);
    }

    public function test_normaliza_abreviaturas_admitidas_del_domicilio(): void
    {
        $normalizador = app(NormalizadorDomicilio::class);
        $domicilio = $normalizador->normalizar([
            'street' => 'Av. Central',
            'exterior_number' => '120',
            'neighborhood' => 'Col. Centro',
            'postal_code' => '27000',
            'municipality' => 'Torreón',
            'city' => 'Torreón',
            'state' => 'Coahuila',
            'country' => 'mx',
        ]);

        self::assertSame('AVENIDA CENTRAL', $domicilio['street']);
        self::assertSame('COLONIA CENTRO', $domicilio['neighborhood']);
    }

    public function test_domicilios_equivalentes_generan_la_misma_huella(): void
    {
        $normalizador = new NormalizadorDomicilio;
        $generador = new GeneradorHuellaDomicilio($normalizador);

        self::assertSame(
            $generador->generar($this->domicilio(['street' => 'Avenida Constitución'])),
            $generador->generar($this->domicilio(['street' => ' avenida  constitucion '])),
        );
    }

    public function test_curp_se_cifra_y_su_hmac_es_determinista(): void
    {
        $protector = new ProtectorDatosCliente(new NormalizadorCurp);
        $cifrada = $protector->cifrarCurp('LOGM900101MCLPRR01');

        self::assertNotSame('LOGM900101MCLPRR01', $cifrada);
        self::assertSame('LOGM900101MCLPRR01', Crypt::decryptString($cifrada));
        self::assertSame($protector->hmacCurp('LOGM900101MCLPRR01'), $protector->hmacCurp('logm-900101-mclprr01'));
    }

    public function test_enmascara_datos_sensibles(): void
    {
        $protector = new ProtectorDatosCliente(new NormalizadorCurp);

        self::assertSame('LOGM***********R01', $protector->enmascarar('LOGM900101MCLPRR01', 4, 3));
        self::assertSame('**************0018', $protector->ultimosCuatro('000000000000000018'));
    }

    private function domicilio(array $cambios = []): array
    {
        return array_replace([
            'street' => 'Avenida Central',
            'exterior_number' => '120',
            'interior_number' => '',
            'neighborhood' => 'Centro',
            'postal_code' => '27000',
            'municipality' => 'Torreón',
            'city' => 'Torreón',
            'state' => 'Coahuila',
            'country' => 'MX',
        ], $cambios);
    }
}
