<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Models\Branch;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Carbon\CarbonImmutable;

class RevisionCoordinadorTest extends Modulo5TestCase
{
    public function test_devolver_a_captura_cambia_estado_y_registra_auditoria()
    {
        $branchId = Branch::factory()->create()->id;
        $coordinator = User::factory()->create();

        $app = DistributorApplication::factory()->create([
            'branch_id' => $branchId, 'coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_REVIEW,
        ]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/return-to-draft", [
                'reason' => 'Falta foto de comprobante', 'pending_sections' => ['address'], 'lock_version' => $app->lock_version,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::DRAFT->value]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'DISTRIBUTOR_APPLICATION_RETURNED_TO_DRAFT', 'entity_id' => $app->id]);
    }

    public function test_asignar_por_alcance_falla_si_es_otra_sucursal()
    {
        $branchA = Branch::factory()->create()->id;
        $branchB = Branch::factory()->create()->id;

        $coordinatorA = User::factory()->create();
        $verifierB = User::factory()->create();
        $verifierB->assignRole('verifier', $branchB);

        $app = DistributorApplication::factory()->create(['branch_id' => $branchA, 'coordinator_id' => $coordinatorA->id, 'status' => ApplicationStatus::COORDINATOR_REVIEW]);

        $response = $this->actingAsMfaUser($coordinatorA, ['coordinator'], $branchA)
            ->postJson("/api/v1/distributor-applications/{$app->id}/assign-verifier", [
                'verifier_id' => $verifierB->id, 'scheduled_for' => now()->addDay()->setTime(15, 0)->toIso8601String(), 'lock_version' => $app->lock_version,
            ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'VERIFIER_BRANCH_MISMATCH');
    }

    public function test_asignacion_exitosa_crea_visita_y_auditoria()
    {
        $branchId = Branch::factory()->create()->id;
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branchId);

        $app = DistributorApplication::factory()->create(['branch_id' => $branchId, 'coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_REVIEW]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/assign-verifier", [
                'verifier_id' => $verifier->id, 'scheduled_for' => now()->addDay()->setTime(15, 0)->toIso8601String(), 'lock_version' => $app->lock_version,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::VERIFIER_ASSIGNED->value]);
        $this->assertDatabaseHas('verification_visits', ['application_id' => $app->id, 'verifier_id' => $verifier->id]);
    }

    public function test_asignacion_con_iso_utc_conserva_la_hora_local_seleccionada(): void
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branch->id);
        $application = DistributorApplication::factory()->create([
            'branch_id' => $branch->id,
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_REVIEW,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-08-25 16:28:00', 'America/Monterrey'));

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branch->id)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $verifier->id,
                'scheduled_for' => '2026-08-25T22:30:00.000Z',
                'lock_version' => $application->lock_version,
            ])
            ->assertOk();

        $visit = VerificationVisit::query()->where('application_id', $application->id)->firstOrFail();

        $this->assertSame('2026-08-25 16:30:00', $visit->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame('America/Monterrey', $visit->scheduled_for->timezoneName);
    }

    public function test_hoy_permite_asignar_desde_el_siguiente_bloque_de_quince_minutos(): void
    {
        $now = CarbonImmutable::parse('2026-08-24 12:01:00', 'America/Monterrey');
        $this->travelTo($now);
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branch->id);

        foreach ([['12:00', 422], ['12:15', 200]] as [$time, $status]) {
            $application = DistributorApplication::factory()->create([
                'branch_id' => $branch->id,
                'coordinator_id' => $coordinator->id,
                'status' => ApplicationStatus::COORDINATOR_REVIEW,
            ]);

            $this->actingAsMfaUser($coordinator, ['coordinator'], $branch->id)
                ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                    'verifier_id' => $verifier->id,
                    'scheduled_for' => $now->setTimeFromTimeString($time)->toIso8601String(),
                    'lock_version' => $application->lock_version,
                ])
                ->assertStatus($status);
        }
    }

    public function test_no_permite_asignar_dentro_de_la_ventana_reservada_del_verificador(): void
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branch->id);
        $scheduled = now()->addDays(2)->setTime(10, 0);

        VerificationVisit::factory()->create([
            'verifier_id' => $verifier->id,
            'scheduled_for' => $scheduled,
        ]);

        foreach ([$scheduled->copy()->subMinutes(15), $scheduled->copy()->addMinutes(45)] as $conflictingTime) {
            $application = DistributorApplication::factory()->create([
                'branch_id' => $branch->id,
                'coordinator_id' => $coordinator->id,
                'status' => ApplicationStatus::COORDINATOR_REVIEW,
            ]);

            $this->actingAsMfaUser($coordinator, ['coordinator'], $branch->id)
                ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                    'verifier_id' => $verifier->id,
                    'scheduled_for' => $conflictingTime->toIso8601String(),
                    'lock_version' => $application->lock_version,
                ])
                ->assertConflict()
                ->assertJsonPath('error.code', 'VERIFIER_SCHEDULE_CONFLICT');
        }
    }

    public function test_permite_asignar_al_cumplir_75_minutos_entre_inicios(): void
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branch->id);
        $scheduled = now()->addDays(2)->setTime(10, 0);
        VerificationVisit::factory()->create(['verifier_id' => $verifier->id, 'scheduled_for' => $scheduled]);
        $application = DistributorApplication::factory()->create([
            'branch_id' => $branch->id,
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_REVIEW,
        ]);

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branch->id)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $verifier->id,
                'scheduled_for' => $scheduled->copy()->addMinutes(75)->toIso8601String(),
                'lock_version' => $application->lock_version,
            ])
            ->assertOk();
    }

    public function test_coordinador_puede_consultar_la_agenda_del_verificador_de_su_sucursal(): void
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branch->id);
        $application = DistributorApplication::factory()->create([
            'branch_id' => $branch->id,
            'coordinator_id' => $coordinator->id,
        ]);
        $scheduled = now()->addDays(2)->setTime(10, 0);
        VerificationVisit::factory()->create(['verifier_id' => $verifier->id, 'scheduled_for' => $scheduled]);

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branch->id)
            ->getJson("/api/v1/distributor-applications/{$application->id}/verifiers/{$verifier->id}/schedule?from={$scheduled->copy()->startOfMonth()->toIso8601String()}&to={$scheduled->copy()->endOfMonth()->toIso8601String()}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.application_number', fn ($value) => is_string($value));
    }

    public function test_lista_solo_verificadores_activos_de_la_sucursal_de_la_solicitud(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $coordinator = User::factory()->create(['state' => 'ACTIVE']);
        $available = User::factory()->create(['state' => 'ACTIVE']);
        $available->assignRole('verifier', $branch->id);
        $other = User::factory()->create(['state' => 'ACTIVE']);
        $other->assignRole('verifier', $otherBranch->id);
        $inactive = User::factory()->create(['state' => 'DISABLED']);
        $inactive->assignRole('verifier', $branch->id);
        $app = DistributorApplication::factory()->create(['branch_id' => $branch->id, 'coordinator_id' => $coordinator->id]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branch->id)
            ->getJson("/api/v1/distributor-applications/{$app->id}/available-verifiers");

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $available->id);
    }

    public function test_otro_coordinador_no_puede_listar_verificadores_de_la_solicitud(): void
    {
        $branch = Branch::factory()->create();
        $assigned = User::factory()->create();
        $other = User::factory()->create();
        $app = DistributorApplication::factory()->create(['branch_id' => $branch->id, 'coordinator_id' => $assigned->id]);

        $this->actingAsMfaUser($other, ['coordinator'], $branch->id)
            ->getJson("/api/v1/distributor-applications/{$app->id}/available-verifiers")
            ->assertForbidden()->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_gerente_de_sucursal_puede_listar_verificadores_de_su_sucursal(): void
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $manager = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branch->id);
        $app = DistributorApplication::factory()->create(['branch_id' => $branch->id, 'coordinator_id' => $coordinator->id]);

        $this->actingAsMfaUser($manager, ['branch_manager'], $branch->id)
            ->getJson("/api/v1/distributor-applications/{$app->id}/available-verifiers")
            ->assertOk()
            ->assertJsonPath('data.0.id', $verifier->id);
    }

    public function test_admin_no_puede_asignar_verificadores_por_ser_solo_consulta(): void
    {
        $branch = Branch::factory()->create();
        $coordinator = User::factory()->create();
        $admin = User::factory()->create();
        $app = DistributorApplication::factory()->create(['branch_id' => $branch->id, 'coordinator_id' => $coordinator->id]);

        $this->actingAsMfaUser($admin, ['admin'])
            ->getJson("/api/v1/distributor-applications/{$app->id}/available-verifiers")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }
}
