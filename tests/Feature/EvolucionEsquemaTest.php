<?php

namespace Tests\Feature;

use App\Enums\ApplicationEvaluationResult;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\RestriccionUsoCredito;
use App\Models\SolicitudIncrementoLinea;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EvolucionEsquemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_existe_la_raiz_canonica_y_las_fk_m05_apuntan_a_ella(): void
    {
        $this->assertTrue(Schema::hasTable('distributor_applications'));
        $this->assertFalse(Schema::hasTable('distributor_applications_m5'));

        $filas = DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tabla, parent.relname AS destino, c.confdeltype
            FROM pg_constraint c
            JOIN pg_class parent ON parent.oid = c.confrelid
            WHERE c.contype = 'f'
              AND c.conname IN (
                'verification_visits_application_id_foreign',
                'application_corrections_application_id_foreign',
                'application_evaluations_application_id_foreign',
                'application_authorizations_application_id_foreign'
              )
        SQL);

        $this->assertCount(4, $filas);
        foreach ($filas as $fila) {
            $this->assertSame('distributor_applications', $fila->destino);
            $this->assertSame('r', $fila->confdeltype);
        }
    }

    public function test_solicitud_admite_multiples_evaluaciones_pero_una_sola_autorizacion(): void
    {
        $solicitud = DistributorApplication::factory()->create();
        $coordinador = User::factory()->create(['state' => 'ACTIVE']);

        foreach ([now()->subMinute(), now()] as $fecha) {
            $visita = VerificationVisit::factory()->create([
                'application_id' => $solicitud->id,
                'status' => VerificationVisitStatus::COMPLETED,
                'result' => VerificationVisitResult::FAVORABLE,
                'completed_at' => $fecha,
            ]);
            ApplicationEvaluation::factory()->create([
                'application_id' => $solicitud->id,
                'verification_visit_id' => $visita->id,
                'evaluated_by' => $coordinador->id,
                'evaluated_at' => $fecha,
                'result' => ApplicationEvaluationResult::COMPLIES,
            ]);
        }

        $this->assertCount(2, $solicitud->evaluations()->get());

        ApplicationAuthorization::factory()->create(['application_id' => $solicitud->id]);
        $this->esperarViolacion(fn () => ApplicationAuthorization::factory()->create(['application_id' => $solicitud->id]));
    }

    public function test_distribuidora_activa_exige_evidencia_y_disabled_conserva_historia(): void
    {
        $distribuidora = Distribuidora::factory()->create();
        $actor = User::factory()->create(['state' => 'ACTIVE']);

        $this->esperarViolacion(fn () => DB::table('distributors')->where('id', $distribuidora->id)->update([
            'status' => 'ACTIVE', 'activated_at' => null, 'activated_by' => $actor->id,
        ]));
        $this->esperarViolacion(fn () => DB::table('distributors')->where('id', $distribuidora->id)->update([
            'status' => 'ACTIVE', 'activated_at' => now(), 'activated_by' => null,
        ]));

        DB::table('distributors')->where('id', $distribuidora->id)->update([
            'status' => 'ACTIVE', 'activated_at' => now(), 'activated_by' => $actor->id,
        ]);
        DB::table('distributors')->where('id', $distribuidora->id)->update(['status' => 'DISABLED']);
        $this->assertDatabaseHas('distributors', ['id' => $distribuidora->id, 'status' => 'DISABLED', 'activated_by' => $actor->id]);
    }

    public function test_restriccion_cumple_integridad_referencial_ciclo_y_unicidad_vigente(): void
    {
        $restriccion = RestriccionUsoCredito::factory()->create([
            'status' => 'ACTIVE',
            'reserved_voucher_id' => null,
            'reserved_at' => null,
        ]);
        $this->esperarViolacion(fn () => RestriccionUsoCredito::factory()->create([
            'credit_line_id' => $restriccion->credit_line_id,
            'distributor_id' => $restriccion->distributor_id,
        ]));

        $this->esperarViolacion(fn () => $restriccion->update([
            'status' => 'RESERVED',
            'reserved_voucher_id' => (string) str()->uuid(),
            'reserved_at' => now(),
        ]));
        $restriccion = $restriccion->fresh();
        $restriccion->update([
            'status' => 'CANCELLED',
            'reserved_voucher_id' => null,
            'reserved_at' => null,
            'cancelled_at' => now(),
        ]);

        RestriccionUsoCredito::factory()->create([
            'credit_line_id' => $restriccion->credit_line_id,
            'distributor_id' => $restriccion->distributor_id,
            'status' => 'ACTIVE',
            'reserved_voucher_id' => null,
            'reserved_at' => null,
        ]);
        $this->assertSame('CANCELLED', $restriccion->refresh()->status->value);

        $activa = RestriccionUsoCredito::factory()->create([
            'status' => 'ACTIVE',
            'reserved_voucher_id' => null,
            'reserved_at' => null,
        ]);
        $activa->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
        $this->assertSame('CANCELLED', $activa->refresh()->status->value);

        $this->esperarViolacion(fn () => RestriccionUsoCredito::factory()->reserved()->create());
    }

    public function test_movimientos_rechazan_snapshots_estructuralmente_imposibles(): void
    {
        $linea = LineaCredito::factory()->create(['total_authorized' => '1000.0000']);
        $base = MovimientoLineaCredito::factory()->raw([
            'credit_line_id' => $linea->id,
            'distributor_id' => $linea->distributor_id,
            'sequence' => 1,
            'amount' => '100.0000',
            'total_authorized_before' => '1000.0000',
            'total_authorized_after' => '1100.0000',
            'used_balance_before' => '0.0000',
            'used_balance_after' => '0.0000',
        ]);

        foreach ([
            ['amount' => '0.0000'],
            ['total_authorized_before' => '0.0000'],
            ['total_authorized_after' => '0.0000'],
            ['used_balance_before' => '-0.0001'],
            ['used_balance_after' => '-0.0001'],
            ['used_balance_before' => '1000.0001'],
            ['used_balance_after' => '1100.0001'],
        ] as $invalido) {
            $this->esperarViolacion(fn () => DB::table('credit_line_movements')->insert(array_merge($base, $invalido, [
                'id' => (string) str()->uuid(),
                'source_id' => (string) str()->uuid(),
                'idempotency_key' => (string) str()->uuid(),
            ])));
        }
    }

    public function test_postgresql_no_conserva_check_hacia_voucher_id_inexistente(): void
    {
        $this->assertFalse(Schema::hasColumn('credit_usage_restrictions', 'voucher_id'));
        $this->assertTrue(Schema::hasColumn('credit_usage_restrictions', 'reserved_voucher_id'));

        $checks = collect(DB::select("SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'credit_usage_restrictions'::regclass AND contype = 'c'"));
        $this->assertFalse($checks->contains(fn ($check) => preg_match('/(?<!reserved_)voucher_id/', $check->definition) === 1));
    }

    public function test_redemption_period_no_tiene_default_y_version_de_configuracion_tiene_fk(): void
    {
        $columna = DB::selectOne("SELECT column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'redemption_periods' AND column_name = 'point_value'");
        $this->assertNull($columna->column_default);

        $fk = DB::selectOne("SELECT confrelid::regclass::text AS destino, confdeltype FROM pg_constraint WHERE conname = 'redemption_periods_point_value_configuration_version_id_foreign'");
        $this->assertSame('configuration_versions', $fk->destino);
        $this->assertSame('r', $fk->confdeltype);
    }

    public function test_historiales_rechazan_estados_desconocidos_y_periodos_no_operativos_admiten_valor_nulo(): void
    {
        $actor = User::factory()->create(['state' => 'ACTIVE']);
        $solicitud = DistributorApplication::factory()->create();
        $incremento = SolicitudIncrementoLinea::factory()->create();

        $this->esperarViolacion(fn () => DB::table('application_state_transitions')->insert([
            'id' => (string) str()->uuid(),
            'application_id' => $solicitud->id,
            'from_status' => 'INVALID',
            'to_status' => 'DRAFT',
            'user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->esperarViolacion(fn () => DB::table('application_state_transitions')->insert([
            'id' => (string) str()->uuid(),
            'application_id' => $solicitud->id,
            'from_status' => null,
            'to_status' => 'INVALID',
            'user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->esperarViolacion(fn () => DB::table('credit_increase_state_transitions')->insert([
            'id' => (string) str()->uuid(),
            'request_id' => $incremento->id,
            'actor_id' => $actor->id,
            'from_status' => 'INVALID',
            'to_status' => 'REQUESTED',
            'created_at' => now(),
        ]));
        $this->esperarViolacion(fn () => DB::table('credit_increase_state_transitions')->insert([
            'id' => (string) str()->uuid(),
            'request_id' => $incremento->id,
            'actor_id' => $actor->id,
            'from_status' => null,
            'to_status' => 'INVALID',
            'created_at' => now(),
        ]));

        $periodoBase = [
            'name' => 'Periodo de prueba',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(2),
            'reason' => 'Validación de integridad',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        foreach (['DRAFT', 'CANCELLED'] as $estado) {
            DB::table('redemption_periods')->insert(array_merge($periodoBase, [
                'id' => (string) str()->uuid(),
                'code' => "RP-{$estado}",
                'status' => $estado,
                'point_value' => null,
                'point_value_configuration_version_id' => null,
            ]));
        }
        foreach (['SCHEDULED', 'OPEN', 'CLOSED'] as $estado) {
            $this->esperarViolacion(fn () => DB::table('redemption_periods')->insert(array_merge($periodoBase, [
                'id' => (string) str()->uuid(),
                'code' => "RP-{$estado}",
                'status' => $estado,
                'point_value' => null,
                'point_value_configuration_version_id' => null,
            ])));
        }
    }

    public function test_upgrade_legacy_vacio_converge_y_payload_ambiguo_aborta(): void
    {
        Schema::create('distributor_applications_m5', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->uuid('coordinator_id')->nullable();
            $table->string('status')->nullable();
            $table->jsonb('applicant_data')->default('{}');
        });

        (require database_path('migrations/2026_08_10_184455_reconcile_distributor_applications.php'))->up();
        (require database_path('migrations/2026_08_10_184456_drop_legacy_distributor_applications_m5.php'))->up();
        $this->assertFalse(Schema::hasTable('distributor_applications_m5'));

        Schema::create('distributor_applications_m5', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->nullable();
            $table->uuid('coordinator_id')->nullable();
            $table->string('status')->nullable();
            $table->jsonb('applicant_data')->default('{}');
        });
        $solicitud = DistributorApplication::factory()->create();
        DB::table('distributor_applications_m5')->insert([
            'id' => $solicitud->id,
            'branch_id' => $solicitud->branch_id,
            'coordinator_id' => $solicitud->coordinator_id,
            'status' => $solicitud->status->value,
            'applicant_data' => json_encode(['personal_info' => ['first_name' => 'Ambiguo']]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('applicant_data');
        (require database_path('migrations/2026_08_10_184455_reconcile_distributor_applications.php'))->up();
    }

    private function esperarViolacion(callable $operacion): void
    {
        try {
            DB::transaction($operacion);
            $this->fail('Se esperaba una violación de integridad PostgreSQL.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
