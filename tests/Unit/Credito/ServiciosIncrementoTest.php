<?php

namespace Tests\Unit\Credito;

use App\Enums\EstadoSolicitudIncremento;
use App\Exceptions\ExcepcionCredito;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\RestriccionUsoCredito;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Credito\ServicioDecisionIncremento;
use App\Services\Credito\ServicioEstadoIncremento;
use App\Services\Credito\ServicioPreautorizacionIncremento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Database\Seeders\TestConfiguracionSeeder;

class ServiciosIncrementoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Mock AuditorIncrementos and OutboxEvent to avoid side effects
        $this->mock(\App\Services\Credito\AuditorIncrementos::class, function ($mock) {
            $mock->shouldReceive('registrar')->andReturn();
        });
        
        $this->mock(\App\Services\ConfiguracionServicio::class, function ($mock) {
            $mock->shouldReceive('resolver')->with('CREDIT_TOLERANCE_AMOUNT')->andReturn([
                'value' => '500.0000',
                'version_id' => 'v1.0.0'
            ]);
        });
    }

    public function test_transiciones_invalidas()
    {
        $servicioEstado = new ServicioEstadoIncremento();
        $solicitud = SolicitudIncrementoLinea::factory()->requested()->make();
        $user = User::factory()->make();

        // REQUESTED -> AUTHORIZED_TOTAL is invalid
        $this->expectException(ExcepcionCredito::class);
        $this->expectExceptionMessage('Transición de estado no permitida');
        $this->expectExceptionCode(400);

        $servicioEstado->transicionar($solicitud, EstadoSolicitudIncremento::AUTHORIZED_TOTAL, $user, 'Motivo');
    }

    public function test_validar_importe_recomendado()
    {
        $servicio = app(ServicioPreautorizacionIncremento::class);
        $solicitud = SolicitudIncrementoLinea::factory()->requested()->create([
            'requested_amount' => '5000.0000',
            'lock_version' => 1
        ]);
        $coordinador = User::factory()->create();

        $this->expectException(ExcepcionCredito::class);
        $this->expectExceptionMessage('El importe recomendado no puede ser mayor al solicitado.');

        $servicio->preautorizar($solicitud, $coordinador, '6000.0000', 'Motivo', 1);
    }

    public function test_validar_importe_autorizado()
    {
        $servicio = app(ServicioDecisionIncremento::class);
        $solicitud = SolicitudIncrementoLinea::factory()->preauthorized()->create([
            'requested_amount' => '5000.0000',
            'recommended_amount' => '4000.0000',
            'lock_version' => 1
        ]);
        $gerente = User::factory()->create();

        $this->expectException(ExcepcionCredito::class);
        $this->expectExceptionMessage('El importe autorizado debe ser menor al solicitado en una aprobación parcial.');

        $servicio->decidir($solicitud, $gerente, 'APPROVE_LOWER', '5500.0000', 'Motivo', 1);
    }

    public function test_autorizacion_total_y_movimientos_con_snapshots_correctos()
    {
        $servicio = app(ServicioDecisionIncremento::class);
        $linea = LineaCredito::factory()->create([
            'total_authorized' => '10000.0000',
            'used_balance' => '2000.0000',
        ]);
        $solicitud = SolicitudIncrementoLinea::factory()->preauthorized()->create([
            'credit_line_id' => $linea->id,
            'distributor_id' => $linea->distributor_id,
            'requested_amount' => '5000.0000',
            'recommended_amount' => '5000.0000',
            'lock_version' => 1
        ]);
        $gerente = User::factory()->create();

        $solicitudResult = $servicio->decidir($solicitud, $gerente, 'APPROVE_REQUESTED', null, 'Aprobado', 1);

        $this->assertEquals(EstadoSolicitudIncremento::AUTHORIZED_TOTAL, $solicitudResult->status);
        $this->assertEquals('5000.0000', $solicitudResult->authorized_amount);
        
        $linea->refresh();
        $this->assertEquals('15000.0000', $linea->total_authorized);
        $this->assertEquals('2000.0000', $linea->used_balance);

        $movimiento = \App\Models\MovimientoLineaCredito::where('credit_line_id', $linea->id)->first();
        $this->assertNotNull($movimiento);
        $this->assertEquals('INCREASE', $movimiento->type);
        $this->assertEquals('5000.0000', $movimiento->amount);
        $this->assertEquals('10000.0000', $movimiento->total_authorized_before);
        $this->assertEquals('15000.0000', $movimiento->total_authorized_after);
        $this->assertEquals('2000.0000', $movimiento->used_balance_before);
        $this->assertEquals('2000.0000', $movimiento->used_balance_after);

        $restriccion = RestriccionUsoCredito::where('credit_line_id', $linea->id)->first();
        $this->assertNotNull($restriccion);
        $this->assertEquals('POST_INCREASE_50_PERCENT', $restriccion->type);
        $this->assertEquals('15000.0000', $restriccion->base_total);
        $this->assertEquals('500.0000', $restriccion->tolerance_amount);
    }

    public function test_rechazo_gerencial()
    {
        $servicio = app(ServicioDecisionIncremento::class);
        $linea = LineaCredito::factory()->create([
            'total_authorized' => '10000.0000',
            'used_balance' => '2000.0000',
        ]);
        $solicitud = SolicitudIncrementoLinea::factory()->preauthorized()->create([
            'credit_line_id' => $linea->id,
            'distributor_id' => $linea->distributor_id,
            'requested_amount' => '5000.0000',
            'recommended_amount' => '4000.0000',
            'lock_version' => 1
        ]);
        $gerente = User::factory()->create();

        $solicitudResult = $servicio->decidir($solicitud, $gerente, 'REJECT', null, 'Rechazado', 1);

        $this->assertEquals(EstadoSolicitudIncremento::REJECTED_BY_MANAGER, $solicitudResult->status);
        $this->assertNull($solicitudResult->authorized_amount);
        
        $linea->refresh();
        $this->assertEquals('10000.0000', $linea->total_authorized);

        $movimientosCount = \App\Models\MovimientoLineaCredito::where('credit_line_id', $linea->id)->count();
        $this->assertEquals(0, $movimientosCount);
        
        $restriccionCount = RestriccionUsoCredito::where('credit_line_id', $linea->id)->count();
        $this->assertEquals(0, $restriccionCount);
    }

    public function test_autorizacion_parcial_aplica_cambios_correctos()
    {
        $servicio = app(ServicioDecisionIncremento::class);
        $linea = LineaCredito::factory()->create([
            'total_authorized' => '10000.0000',
            'used_balance' => '2000.0000',
        ]);
        $solicitud = SolicitudIncrementoLinea::factory()->preauthorized()->create([
            'credit_line_id' => $linea->id,
            'distributor_id' => $linea->distributor_id,
            'requested_amount' => '5000.0000',
            'recommended_amount' => '4000.0000',
            'lock_version' => 1
        ]);
        $gerente = User::factory()->create();

        $solicitudResult = $servicio->decidir($solicitud, $gerente, 'APPROVE_LOWER', '3000.0000', 'Aprobado parcial', 1);

        $this->assertEquals(EstadoSolicitudIncremento::AUTHORIZED_PARTIAL, $solicitudResult->status);
        $this->assertEquals('3000.0000', $solicitudResult->authorized_amount);
        
        $linea->refresh();
        $this->assertEquals('13000.0000', $linea->total_authorized);
    }
}
