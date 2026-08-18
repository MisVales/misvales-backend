<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Models\Branch;
use App\Models\DistributorApplication;
use App\Models\User;

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
                'verifier_id' => $verifierB->id, 'lock_version' => $app->lock_version,
            ]);

        $response->assertStatus(403)->assertJsonPath('error', 'VERIFIER_BRANCH_MISMATCH');
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
                'verifier_id' => $verifier->id, 'lock_version' => $app->lock_version,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::VERIFIER_ASSIGNED->value]);
        $this->assertDatabaseHas('verification_visits', ['application_id' => $app->id, 'verifier_id' => $verifier->id]);
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
            ->assertForbidden()->assertJsonPath('error', 'AUTH_SCOPE_DENIED');
    }
}
