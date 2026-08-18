<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;

class EvaluacionSolicitudTest extends Modulo5TestCase
{
    public function test_evaluacion_favorable_envia_a_gerente()
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create(['coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_EVALUATION]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'result' => VerificationVisitResult::FAVORABLE, 'status' => 'COMPLETED']);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/evaluate", [
                'visit_id' => $visit->id, 'result' => 'COMPLIES', 'reason' => 'Validado', 'lock_version' => 1,
            ]);

        $response->assertStatus(200); // Because we changed it to implicitly return Response
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::MANAGER_AUTHORIZATION->value]);
    }
}
