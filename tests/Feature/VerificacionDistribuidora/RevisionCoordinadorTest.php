<?php
namespace Tests\Feature\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\AuditLog;
use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Testing\WithFaker;

class RevisionCoordinadorTest extends Modulo5TestCase {
    
    public function test_devolver_a_captura_cambia_estado_y_registra_auditoria() {
        $branchId = \Illuminate\Support\Str::uuid();
        $coordinator = User::factory()->create();
        
        $app = DistributorApplication::factory()->create([
            'branch_id' => $branchId, 'coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_REVIEW
        ]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/return-to-draft", [
                'reason' => 'Falta foto de comprobante', 'pending_sections' => ['address'], 'lock_version' => $app->lock_version
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications_m5', ['id' => $app->id, 'status' => ApplicationStatus::DRAFT->value]);
        $this->assertDatabaseHas('audit_logs', ['event_type' => 'DISTRIBUTOR_APPLICATION_RETURNED_TO_DRAFT', 'entity_id' => $app->id]);
    }

    public function test_asignar_por_alcance_falla_si_es_otra_sucursal() {
        $branchA = \Illuminate\Support\Str::uuid();
        $branchB = \Illuminate\Support\Str::uuid();
        
        $coordinatorA = User::factory()->create();
        $verifierB = User::factory()->create();
        $verifierB->assignRole('verifier', $branchB);

        $app = DistributorApplication::factory()->create(['branch_id' => $branchA, 'coordinator_id' => $coordinatorA->id, 'status' => ApplicationStatus::COORDINATOR_REVIEW]);

        $response = $this->actingAsMfaUser($coordinatorA, ['coordinator'], $branchA)
            ->postJson("/api/v1/distributor-applications/{$app->id}/assign-verifier", [
                'verifier_id' => $verifierB->id, 'lock_version' => $app->lock_version
            ]);

        $response->assertStatus(403)->assertJsonPath('error', 'VERIFIER_BRANCH_MISMATCH');
    }

    public function test_asignacion_exitosa_crea_visita_y_auditoria() {
        $branchId = \Illuminate\Support\Str::uuid();
        $coordinator = User::factory()->create();
        $verifier = User::factory()->create(['state' => 'ACTIVE']);
        $verifier->assignRole('verifier', $branchId);

        $app = DistributorApplication::factory()->create(['branch_id' => $branchId, 'coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_REVIEW]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/assign-verifier", [
                'verifier_id' => $verifier->id, 'lock_version' => $app->lock_version
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications_m5', ['id' => $app->id, 'status' => ApplicationStatus::VERIFIER_ASSIGNED->value]);
        $this->assertDatabaseHas('verification_visits', ['application_id' => $app->id, 'verifier_id' => $verifier->id]);
    }
}
