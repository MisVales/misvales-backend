<?php

namespace Tests\Unit\SolicitudDistribuidora;

use App\Enums\CodigoErrorSolicitudDistribuidora;
use App\Enums\EstadoDeclaracionSeccion;
use App\Enums\EstadoSolicitudDistribuidora;
use App\Models\CreditoComercialSolicitud;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DomicilioSolicitud;
use App\Models\EmpleoSolicitud;
use App\Models\FamiliarSolicitud;
use App\Models\PatrimonioSolicitud;
use App\Models\SolicitudDistribuidora;
use App\Models\VehiculoSolicitud;
use App\Services\SolicitudDistribuidora\ValidadorExpedienteSolicitud;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Tests\TestCase;

final class ModelosSolicitudDistribuidoraTest extends TestCase
{
    public function test_los_codigos_estables_y_contrato_del_validador_estan_completos(): void
    {
        self::assertSame([
            'DISTRIBUTOR_APPLICATION_NOT_FOUND',
            'DISTRIBUTOR_APPLICATION_NOT_EDITABLE',
            'DISTRIBUTOR_APPLICATION_INCOMPLETE',
            'DISTRIBUTOR_APPLICATION_ALREADY_SUBMITTED',
            'DISTRIBUTOR_APPLICATION_BRANCH_INVALID',
            'DISTRIBUTOR_APPLICATION_COORDINATOR_INVALID',
            'DISTRIBUTOR_APPLICATION_SECTION_INCOMPLETE',
            'DISTRIBUTOR_APPLICATION_SECTION_NOT_APPLICABLE',
            'DISTRIBUTOR_APPLICATION_CURRENT_RESIDENCE_REQUIRED',
            'DISTRIBUTOR_APPLICATION_CURRENT_RESIDENCE_DUPLICATE',
            'DISTRIBUTOR_APPLICATION_CHILD_NOT_FOUND',
            'DISTRIBUTOR_APPLICATION_INVALID_TRANSITION',
            'DISTRIBUTOR_APPLICATION_SENSITIVE_DATA_INVALID',
            'RESOURCE_VERSION_CONFLICT',
            'AUTH_SCOPE_DENIED',
        ], array_column(CodigoErrorSolicitudDistribuidora::cases(), 'value'));

        foreach ([
            'validarSecciones',
            'validarDatosPersonales',
            'validarDomicilioActual',
            'validarDeclaraciones',
            'validarEnvio',
            'calcularSeccionesCompletas',
        ] as $metodo) {
            self::assertTrue(method_exists(ValidadorExpedienteSolicitud::class, $metodo));
        }
    }

    public function test_los_estados_persistidos_del_flujo_estan_definidos(): void
    {
        self::assertSame([
            'DRAFT',
            'COORDINATOR_REVIEW',
            'VERIFIER_ASSIGNED',
            'PHYSICAL_VERIFICATION',
            'COORDINATOR_CORRECTION',
            'COORDINATOR_EVALUATION',
            'MANAGER_AUTHORIZATION',
            'TERMINATED_UNFAVORABLE',
            'REJECTED',
            'ACTIVE',
        ], array_column(EstadoSolicitudDistribuidora::cases(), 'value'));

        self::assertSame(
            ['PENDING', 'COMPLETED', 'NOT_APPLICABLE'],
            array_column(EstadoDeclaracionSeccion::cases(), 'value'),
        );
    }

    public function test_la_solicitud_declara_tabla_casts_y_relaciones(): void
    {
        $solicitud = new SolicitudDistribuidora;
        $solicitud->forceFill([
            'status' => 'DRAFT',
            'section_declarations' => ['personal_data' => 'PENDING'],
            'lock_version' => '3',
        ]);

        self::assertSame('distributor_applications', $solicitud->getTable());
        self::assertSame(EstadoSolicitudDistribuidora::BORRADOR, $solicitud->status);
        self::assertSame(['personal_data' => 'PENDING'], $solicitud->section_declarations);
        self::assertSame(3, $solicitud->lock_version);
        self::assertInstanceOf(BelongsTo::class, $solicitud->sucursal());
        self::assertInstanceOf(BelongsTo::class, $solicitud->coordinador());
        self::assertInstanceOf(BelongsTo::class, $solicitud->creador());
        self::assertInstanceOf(HasOne::class, $solicitud->datosPersonales());
        self::assertInstanceOf(HasMany::class, $solicitud->familiares());
        self::assertInstanceOf(HasMany::class, $solicitud->domicilios());
        self::assertInstanceOf(HasMany::class, $solicitud->vehiculos());
        self::assertInstanceOf(HasMany::class, $solicitud->patrimonio());
        self::assertInstanceOf(HasMany::class, $solicitud->empleos());
        self::assertInstanceOf(HasMany::class, $solicitud->creditosComerciales());
    }

    public function test_los_modelos_hijos_declaran_sus_tablas_y_pertenencia(): void
    {
        $modelos = [
            [new DatosPersonalesSolicitud, 'application_personal_data'],
            [new FamiliarSolicitud, 'application_family_members'],
            [new DomicilioSolicitud, 'application_residences'],
            [new VehiculoSolicitud, 'application_vehicles'],
            [new PatrimonioSolicitud, 'application_assets_liabilities'],
            [new EmpleoSolicitud, 'application_employments'],
            [new CreditoComercialSolicitud, 'application_commercial_credits'],
        ];

        foreach ($modelos as [$modelo, $tabla]) {
            self::assertSame($tabla, $modelo->getTable());
            self::assertInstanceOf(BelongsTo::class, $modelo->solicitud());
        }
    }

    public function test_los_importes_se_mantienen_como_strings_decimales(): void
    {
        $patrimonio = new PatrimonioSolicitud;
        $patrimonio->forceFill([
            'amount' => '1200.5',
            'outstanding_balance' => '10',
            'monthly_payment' => '1.23456',
        ]);
        $credito = new CreditoComercialSolicitud;
        $credito->forceFill(['credit_limit' => '5000.25']);

        self::assertSame('1200.5000', $patrimonio->amount);
        self::assertSame('10.0000', $patrimonio->outstanding_balance);
        self::assertSame('1.2346', $patrimonio->monthly_payment);
        self::assertSame('5000.2500', $credito->credit_limit);
    }

    public function test_los_datos_sensibles_no_se_serializan_ni_admiten_asignacion_masiva(): void
    {
        $datos = new DatosPersonalesSolicitud;

        self::assertNotContains('curp_ciphertext', $datos->getFillable());
        self::assertNotContains('curp_hmac', $datos->getFillable());
        self::assertNotContains('official_id_number_ciphertext', $datos->getFillable());
        self::assertContains('curp_ciphertext', $datos->getHidden());
        self::assertContains('rfc_hmac', $datos->getHidden());
        self::assertContains('official_id_number_hmac', $datos->getHidden());
    }
}
