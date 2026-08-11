<?php
namespace Tests\Feature\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\ApplicationEvaluation;
use App\Models\User;
use App\Enums\ApplicationStatus;
use App\Enums\ApplicationEvaluationResult;

class AutorizacionGerencialTest extends Modulo5TestCase {
    public function test_linea_inicial_obligatoria_para_aprobacion() {
        $branchId = \App\Models\Branch::factory()->create()->id;
        $manager = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::MANAGER_AUTHORIZATION, 'branch_id' => $branchId]);
        ApplicationEvaluation::factory()->create(['application_id' => $app->id, 'result' => ApplicationEvaluationResult::COMPLIES]);

        $response = $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/authorize", [
                'reason' => 'Aprobado', 'lock_version' => 1
                // Faltan monto
            ]);

        $response->assertStatus(422)->assertJsonPath('error', 'APPLICATION_INITIAL_CREDIT_LINE_REQUIRED');
    }

    public function test_aislamiento_no_crea_datos_operativos_aun() {
        $branchId = \App\Models\Branch::factory()->create()->id;
        $manager = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::MANAGER_AUTHORIZATION, 'branch_id' => $branchId]);
        ApplicationEvaluation::factory()->create(['application_id' => $app->id, 'result' => ApplicationEvaluationResult::COMPLIES]);

        $response = $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/authorize", [
                'initial_credit_line_amount' => 15000, 'reason' => 'Aprobado', 'lock_version' => 1
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION->value]);
        
        // Validate isolation (No user or credit line created, handled in Modulo 6)
        $this->assertDatabaseCount('distributors', 0);
        $this->assertDatabaseCount('credit_lines', 0);
    }
}
