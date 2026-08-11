<?php

namespace Tests\Feature;

use App\Enums\RedemptionPeriodStatus;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\RedemptionPeriod;
use App\Models\User;
use App\Services\PeriodoCanjeServicio;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PeriodoCanjeServicioTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::forget('periodo_canje:vigente');
        parent::tearDown();
    }

    public function test_publicar_programa_el_periodo_y_el_resolver_abre_y_cierra_segun_sus_fechas(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        $usuario = User::factory()->create();
        $versionConfiguracion = $this->crearVersionValorPunto($usuario);
        $periodo = RedemptionPeriod::create([
            'code' => 'AGO-2026',
            'name' => 'Canje agosto',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => RedemptionPeriodStatus::DRAFT,
            'point_value' => '1.2500',
            'point_value_configuration_version_id' => $versionConfiguracion->id,
            'reason' => 'Alta controlada',
            'created_by' => $usuario->id,
        ]);

        $servicio = app(PeriodoCanjeServicio::class);
        $publicado = $servicio->publicarPeriodo($periodo, [
            'reason' => 'Calendario autorizado',
            'lock_version' => $periodo->lock_version,
        ], $usuario->id);

        self::assertSame(RedemptionPeriodStatus::SCHEDULED, $publicado->status);
        self::assertNull($servicio->resolverVigente());

        Carbon::setTestNow('2026-08-11 13:30:00 UTC');
        Cache::forget('periodo_canje:vigente');
        self::assertSame($periodo->id, $servicio->resolverVigente()['id']);
        self::assertSame(RedemptionPeriodStatus::OPEN, $periodo->fresh()->status);

        Carbon::setTestNow('2026-08-11 14:01:00 UTC');
        Cache::forget('periodo_canje:vigente');
        self::assertNull($servicio->resolverVigente());
        self::assertSame(RedemptionPeriodStatus::CLOSED, $periodo->fresh()->status);
    }

    public function test_publicacion_detecta_traslapes_con_periodos_programados(): void
    {
        Carbon::setTestNow('2026-08-11 12:00:00 UTC');
        $usuario = User::factory()->create();
        $versionConfiguracion = $this->crearVersionValorPunto($usuario);

        RedemptionPeriod::create([
            'code' => 'EXISTENTE',
            'name' => 'Existente',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(3),
            'status' => RedemptionPeriodStatus::SCHEDULED,
            'point_value' => '1.2500',
            'point_value_configuration_version_id' => $versionConfiguracion->id,
            'reason' => 'Existente',
            'created_by' => $usuario->id,
            'published_by' => $usuario->id,
            'published_at' => now(),
        ]);
        $nuevo = RedemptionPeriod::create([
            'code' => 'NUEVO',
            'name' => 'Nuevo',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(4),
            'status' => RedemptionPeriodStatus::DRAFT,
            'point_value' => '1.2500',
            'point_value_configuration_version_id' => $versionConfiguracion->id,
            'reason' => 'Nuevo',
            'created_by' => $usuario->id,
        ]);

        $this->expectExceptionMessage('se traslapa');
        app(PeriodoCanjeServicio::class)->publicarPeriodo($nuevo, [
            'reason' => 'Intento de publicar',
            'lock_version' => $nuevo->lock_version,
        ], $usuario->id);
    }

    private function crearVersionValorPunto(User $usuario): ConfigurationVersion
    {
        $definicion = ConfigurationDefinition::create([
            'key' => 'POINT_VALUE_AMOUNT_TEST',
            'name' => 'Valor del punto de prueba',
            'value_type' => 'DECIMAL',
            'status' => 'ACTIVE',
            'created_by' => $usuario->id,
        ]);

        return ConfigurationVersion::create([
            'configuration_definition_id' => $definicion->id,
            'version' => 1,
            'value' => '1.2500',
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(),
            'reason' => 'Prueba',
            'created_by' => $usuario->id,
            'published_by' => $usuario->id,
            'published_at' => now(),
        ]);
    }
}
