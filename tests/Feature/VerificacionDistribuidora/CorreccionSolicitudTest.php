<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;

class CorreccionSolicitudTest extends Modulo5TestCase
{
    public function test_solo_el_coordinador_puede_corregir()
    {
        $coordinator = User::factory()->create();
        $otherUser = User::factory()->create();
        $app = DistributorApplication::factory()->create(['coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_CORRECTION]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'differences_payload' => ['items' => [['section' => 'personal_info', 'field' => 'first_name']]]]);

        $response = $this->actingAsMfaUser($otherUser, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id, 'section' => 'personal_info', 'field_path' => 'first_name', 'new_value' => 'New', 'reason' => 'Fix', 'lock_version' => 1,
            ]);

        $response->assertStatus(403)->assertJsonPath('error', 'AUTH_SCOPE_DENIED');
    }

    public function test_diferencias_pendientes_bloquean_finalizacion()
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create(['coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_CORRECTION]);
        // Visita tiene 1 diferencia reportada
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'differences_payload' => ['items' => [['section' => 'personal_info', 'field' => 'first_name']]]]);

        // El coordinador intenta finalizar SIN haber corregido nada (ApplicationCorrectionCount = 0)
        $response = $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections/finish", ['lock_version' => 1]);

        $response->assertStatus(409)->assertJsonPath('error', 'APPLICATION_CORRECTIONS_PENDING');
    }
}
