<?php
namespace Tests\Feature\VerificacionDistribuidora;
use App\Models\DistributorApplication;
use App\Models\VerificationVisit;
use App\Models\MediaFile;
use App\Models\User;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Enums\VerificationVisitResult;

class VerificacionVisitaTest extends Modulo5TestCase {
    
    public function test_acceso_exclusivo_a_visitas_asignadas() {
        $branchId = \Illuminate\Support\Str::uuid();
        $verifierA = User::factory()->create();
        $verifierB = User::factory()->create();

        $visitA = VerificationVisit::factory()->create(['verifier_id' => $verifierA->id]);

        $response = $this->actingAsMfaUser($verifierB, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visitA->id}/start", ['lock_version' => 1]);

        $response->assertStatus(403)->assertJsonPath('error', 'VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER');
    }

    public function test_iniciar_visita_transiciona_estado() {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::ASSIGNED]);

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->postJson("/api/v1/verification-visits/{$visit->id}/start", ['lock_version' => 1]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('verification_visits', ['id' => $visit->id, 'status' => VerificationVisitStatus::IN_PROGRESS->value]);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::PHYSICAL_VERIFICATION->value]);
    }

    public function test_registro_de_diferencias() {
        $verifier = User::factory()->create();
        $visit = VerificationVisit::factory()->create(['verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::IN_PROGRESS]);

        $payload = ['has_differences' => true, 'items' => [['section' => 'personal_info', 'field' => 'first_name']]];

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->patchJson("/api/v1/verification-visits/{$visit->id}", ['differences_payload' => $payload, 'lock_version' => 1]);

        $response->assertStatus(200);
        $this->assertEquals(true, VerificationVisit::find($visit->id)->differences_payload['has_differences']);
    }

    public function test_visita_desfavorable_termina_solicitud() {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::PHYSICAL_VERIFICATION]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::IN_PROGRESS]);
        
        // Simular evidencia existente (requerido para finalizar)
        MediaFile::factory()->create(['verification_visit_id' => $visit->id]);

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->postJson("/api/v1/verification-visits/{$visit->id}/complete", [
                'result' => VerificationVisitResult::UNFAVORABLE->value,
                'observations' => 'No vive ahi',
                'lock_version' => $visit->lock_version
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::TERMINATED_UNFAVORABLE->value]);
    }
}
