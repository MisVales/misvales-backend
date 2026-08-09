<?php

namespace Tests\Unit\Distribuidora;

use App\Enums\ApplicationEvaluationResult;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Exceptions\ExcepcionDistribuidora;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Services\Distribuidora\ValidadorActivacionDistribuidora;
use Tests\TestCase;

class ValidadorActivacionDistribuidoraTest extends TestCase
{
    private ValidadorActivacionDistribuidora $validador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validador = new ValidadorActivacionDistribuidora;
    }

    public function test_acepta_solicitud_aprobada_con_linea_positiva(): void
    {
        $solicitud = new DistributorApplication(['status' => 'AUTHORIZED_PENDING_ACTIVATION']);
        $autorizacion = new ApplicationAuthorization(['initial_credit_line_amount' => '15000.0000']);
        $autorizacion->forceFill(['decision' => 'APPROVED']);

        $this->validador->validarSolicitud($solicitud, $autorizacion);

        self::assertTrue(true);
    }

    public function test_rechaza_solicitud_sin_aprobacion_favorable(): void
    {
        $solicitud = new DistributorApplication(['status' => 'REJECTED']);
        $autorizacion = new ApplicationAuthorization(['initial_credit_line_amount' => null]);
        $autorizacion->forceFill(['decision' => 'REJECTED']);

        $this->expectExceptionObject(new ExcepcionDistribuidora(
            'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
            'La solicitud no cuenta con una autorización gerencial favorable.',
            409,
        ));

        $this->validador->validarSolicitud($solicitud, $autorizacion);
    }

    public function test_rechaza_linea_inicial_no_positiva(): void
    {
        $solicitud = new DistributorApplication(['status' => 'AUTHORIZED_PENDING_ACTIVATION']);
        $autorizacion = new ApplicationAuthorization(['initial_credit_line_amount' => '0.0000']);
        $autorizacion->forceFill(['decision' => 'APPROVED']);

        try {
            $this->validador->validarSolicitud($solicitud, $autorizacion);
            self::fail('Se esperaba una excepción de dominio.');
        } catch (ExcepcionDistribuidora $excepcion) {
            self::assertSame('DISTRIBUTOR_INITIAL_CREDIT_INVALID', $excepcion->codigo);
            self::assertSame(422, $excepcion->estadoHttp);
        }
    }

    public function test_rechaza_linea_inicial_negativa(): void
    {
        $solicitud = new DistributorApplication(['status' => 'AUTHORIZED_PENDING_ACTIVATION']);
        $autorizacion = new ApplicationAuthorization(['initial_credit_line_amount' => '-0.0001']);
        $autorizacion->forceFill(['decision' => 'APPROVED']);

        $this->expectException(ExcepcionDistribuidora::class);
        $this->validador->validarSolicitud($solicitud, $autorizacion);
    }

    public function test_rechaza_categoria_en_borrador(): void
    {
        $categoria = new Category(['status' => 'ACTIVE']);
        $version = new CategoryVersion([
            'status' => 'DRAFT',
            'effective_from' => now()->subDay(),
        ]);
        $version->setRelation('category', $categoria);

        try {
            $this->validador->validarCategoria($version);
            self::fail('Se esperaba una excepción de dominio.');
        } catch (ExcepcionDistribuidora $excepcion) {
            self::assertSame('DISTRIBUTOR_CATEGORY_NOT_PUBLISHED', $excepcion->codigo);
        }
    }

    public function test_rechaza_categoria_publicada_fuera_de_vigencia(): void
    {
        $categoria = new Category(['status' => 'ACTIVE']);
        $version = new CategoryVersion([
            'status' => 'PUBLISHED',
            'effective_from' => now()->addDay(),
        ]);
        $version->setRelation('category', $categoria);

        try {
            $this->validador->validarCategoria($version);
            self::fail('Se esperaba una excepción de dominio.');
        } catch (ExcepcionDistribuidora $excepcion) {
            self::assertSame('DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE', $excepcion->codigo);
        }
    }

    public function test_rechaza_categoria_publicada_ya_vencida(): void
    {
        $categoria = new Category(['status' => 'ACTIVE']);
        $version = new CategoryVersion([
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDays(2),
            'effective_to' => now()->subDay(),
        ]);
        $version->setRelation('category', $categoria);

        $this->expectException(ExcepcionDistribuidora::class);
        $this->validador->validarCategoria($version);
    }

    public function test_rechaza_categoria_inactiva(): void
    {
        $categoria = new Category(['status' => 'INACTIVE']);
        $version = new CategoryVersion(['status' => 'PUBLISHED', 'effective_from' => now()->subDay()]);
        $version->setRelation('category', $categoria);

        $this->expectException(ExcepcionDistribuidora::class);
        $this->validador->validarCategoria($version);
    }

    public function test_rechaza_sucursal_inactiva(): void
    {
        $sucursal = new Branch(['status' => 'INACTIVE']);
        $sucursal->id = fake()->uuid();
        $solicitud = new DistributorApplication(['branch_id' => $sucursal->id]);

        try {
            $this->validador->validarSucursal($sucursal, $solicitud);
            self::fail('Se esperaba una excepción de dominio.');
        } catch (ExcepcionDistribuidora $excepcion) {
            self::assertSame('DISTRIBUTOR_BRANCH_MISMATCH', $excepcion->codigo);
        }
    }

    public function test_exige_visita_completada_y_evaluacion_favorable_vinculada(): void
    {
        $visita = new VerificationVisit;
        $visita->id = fake()->uuid();
        $visita->forceFill([
            'status' => VerificationVisitStatus::COMPLETED,
            'result' => VerificationVisitResult::FAVORABLE,
        ]);
        $evaluacion = new ApplicationEvaluation(['verification_visit_id' => $visita->id]);
        $evaluacion->forceFill(['result' => ApplicationEvaluationResult::COMPLIES]);

        $this->validador->validarVerificacion($visita, $evaluacion);
        self::assertTrue(true);
    }
}
