<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationStatus;
use App\Models\ApplicationEvaluation;
use App\Models\Branch;
use App\Models\DistributorApplication;
use App\Models\User;

class AutorizacionGerencialTest extends Modulo5TestCase
{
    public function test_linea_inicial_es_obligatoria_para_aprobacion()
    {
        $branchId = Branch::factory()->create()->id;
        $manager = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::MANAGER_AUTHORIZATION, 'branch_id' => $branchId]);
        ApplicationEvaluation::factory()->create(['application_id' => $app->id, 'result' => ApplicationEvaluationResult::COMPLIES]);

        $response = $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/authorize", [
                'decision' => 'APPROVED',
                'reason' => 'Aprobado', 'lock_version' => 1,
                // Faltan monto
            ]);

        $response->assertStatus(422)->assertJsonPath('error', 'APPLICATION_INITIAL_CREDIT_LINE_REQUIRED');
    }

    public function test_aislamiento_no_crea_datos_operativos_aun()
    {
        $branchId = Branch::factory()->create()->id;
        $manager = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::MANAGER_AUTHORIZATION, 'branch_id' => $branchId]);
        ApplicationEvaluation::factory()->create(['application_id' => $app->id, 'result' => ApplicationEvaluationResult::COMPLIES]);

        $response = $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => 15000,
                'reason' => 'Aprobado', 'lock_version' => 1,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION->value]);

        // Validate isolation (No user or credit line created, handled in Modulo 6)
        $this->assertDatabaseCount('distributors', 0);
        $this->assertDatabaseCount('credit_lines', 0);
    }

    public function test_rechazo_exige_monto_nulo(): void
    {
        $branchId = Branch::factory()->create()->id;
        $manager = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::MANAGER_AUTHORIZATION, 'branch_id' => $branchId]);
        ApplicationEvaluation::factory()->create(['application_id' => $app->id, 'result' => ApplicationEvaluationResult::COMPLIES]);

        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$app->id}/authorize", [
                'decision' => 'REJECTED',
                'reason' => 'Riesgo no aceptable',
                'lock_version' => 1,
            ])->assertOk();

        $this->assertDatabaseHas('application_authorizations', [
            'application_id' => $app->id,
            'decision' => 'REJECTED',
            'initial_credit_line_amount' => null,
        ]);
    }
}
