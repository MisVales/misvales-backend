<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Models\Branch;
use App\Models\DistributorApplication;
use App\Models\MediaFile;
use App\Models\MediaFileBinding;
use App\Models\User;
use App\Models\VerificationVisit;

class VerificacionVisitaTest extends Modulo5TestCase
{
    public function test_acceso_exclusivo_a_visitas_asignadas()
    {
        $branchId = Branch::factory()->create()->id;
        $verifierA = User::factory()->create();
        $verifierB = User::factory()->create();

        $visitA = VerificationVisit::factory()->create(['verifier_id' => $verifierA->id]);

        $response = $this->actingAsMfaUser($verifierB, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visitA->id}/start", ['lock_version' => 1]);

        $response->assertStatus(403)->assertJsonPath('error', 'VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER');
    }

    public function test_iniciar_visita_transiciona_estado()
    {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::ASSIGNED]);

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->postJson("/api/v1/verification-visits/{$visit->id}/start", ['lock_version' => 1]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('verification_visits', ['id' => $visit->id, 'status' => VerificationVisitStatus::IN_PROGRESS->value]);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::PHYSICAL_VERIFICATION->value]);
    }

    public function test_registro_de_diferencias()
    {
        $verifier = User::factory()->create();
        $visit = VerificationVisit::factory()->create(['verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::IN_PROGRESS]);

        $payload = ['has_differences' => true, 'items' => [[
            'section' => 'personal_info',
            'field' => 'first_name',
            'declared_value' => 'Nombre declarado',
            'observed_value' => 'Nombre observado',
            'description' => 'El documento original muestra un nombre distinto.',
        ]]];

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->putJson("/api/v1/verification-visits/{$visit->id}/differences", ['differences_payload' => $payload, 'lock_version' => 1]);

        $response->assertStatus(200);
        $this->assertEquals(true, VerificationVisit::find($visit->id)->differences_payload['has_differences']);
    }

    public function test_consulta_visita_asignada_incluye_expediente_sin_usar_el_endpoint_general(): void
    {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
        $visit = VerificationVisit::factory()->create([
            'application_id' => $app->id,
            'verifier_id' => $verifier->id,
            'status' => VerificationVisitStatus::ASSIGNED,
        ]);

        $this->actingAsMfaUser($verifier, ['verifier'])
            ->getJson("/api/v1/verification-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.application.id', (string) $app->id)
            ->assertJsonPath('data.application.application_number', $app->application_number);
    }

    public function test_rechaza_estructura_invalida_de_diferencias(): void
    {
        $verifier = User::factory()->create();
        $visit = VerificationVisit::factory()->create(['verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::IN_PROGRESS]);

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->putJson("/api/v1/verification-visits/{$visit->id}/differences", [
                'differences_payload' => ['has_differences' => false, 'items' => [[
                    'section' => 'invented_section', 'field' => '../invalid',
                ]]],
                'lock_version' => $visit->lock_version,
            ]);

        $response->assertUnprocessable();
        $this->assertNull(VerificationVisit::find($visit->id)->differences_payload);
    }

    public function test_visita_desfavorable_termina_solicitud()
    {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::PHYSICAL_VERIFICATION]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'verifier_id' => $verifier->id, 'status' => VerificationVisitStatus::IN_PROGRESS]);

        // Simular evidencia existente (requerido para finalizar)
        $media = MediaFile::factory()->create();
        MediaFileBinding::query()->create([
            'media_file_id' => $media->id,
            'owner_type' => 'verification_visit',
            'owner_id' => $visit->id,
            'purpose' => 'evidence',
            'created_by' => $verifier->id,
        ]);

        $response = $this->actingAsMfaUser($verifier, ['verifier'])
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'result' => VerificationVisitResult::UNFAVORABLE->value,
                'observations' => 'No vive ahi',
                'lock_version' => $visit->lock_version,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('distributor_applications', ['id' => $app->id, 'status' => ApplicationStatus::TERMINATED_UNFAVORABLE->value]);
    }
}
