<?php

namespace Tests\Unit\Distribuidora;

use App\Exceptions\ExcepcionDistribuidora;
use App\Models\ApplicationAuthorization;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\DistributorApplication;
use App\Services\Distribuidora\ValidadorActivacionDistribuidora;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidadorActivacionDistribuidoraTest extends TestCase
{
    private ValidadorActivacionDistribuidora $validador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validador = new ValidadorActivacionDistribuidora;
    }

    public function test_acepta_decision_formal_autorizada_sin_linea_de_credito(): void
    {
        $solicitud = new DistributorApplication(['status' => 'AUTHORIZED_PENDING_ACTIVATION']);
        $autorizacion = new ApplicationAuthorization(['initial_credit_line_amount' => null]);
        $autorizacion->forceFill(['decision' => 'APPROVED']);
        $this->validador->validarSolicitud($solicitud, $autorizacion);
        self::assertTrue(true);
    }

    public function test_rechaza_solicitud_sin_decision_favorable(): void
    {
        $solicitud = new DistributorApplication(['status' => 'REJECTED']);
        $autorizacion = new ApplicationAuthorization;
        $autorizacion->forceFill(['decision' => 'REJECTED']);
        $this->expectException(ExcepcionDistribuidora::class);
        $this->validador->validarSolicitud($solicitud, $autorizacion);
    }

    public function test_acepta_categoria_activa_publicada_y_vigente(): void
    {
        $categoria = new Category(['status' => 'ACTIVE']);
        $version = new CategoryVersion(['status' => 'PUBLISHED', 'effective_from' => now()->subDay()]);
        $version->setRelation('category', $categoria);
        $this->validador->validarCategoria($version);
        self::assertTrue(true);
    }

    #[DataProvider('categoriasInvalidas')]
    public function test_rechaza_categoria_no_disponible(string $estadoCategoria, string $estadoVersion, int $inicioDias): void
    {
        $categoria = new Category(['status' => $estadoCategoria]);
        $version = new CategoryVersion(['status' => $estadoVersion, 'effective_from' => now()->addDays($inicioDias)]);
        $version->setRelation('category', $categoria);
        $this->expectException(ExcepcionDistribuidora::class);
        $this->validador->validarCategoria($version);
    }

    public static function categoriasInvalidas(): array
    {
        return [['ACTIVE', 'DRAFT', -1], ['INACTIVE', 'PUBLISHED', -1], ['ACTIVE', 'PUBLISHED', 1]];
    }
}
