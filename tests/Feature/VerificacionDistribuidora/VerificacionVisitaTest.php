<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Models\Branch;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DistributorApplication;
use App\Models\AuditLog;
use App\Models\MediaFile;
use App\Models\MediaFileBinding;
use App\Models\User;
use App\Models\VehiculoSolicitud;
use App\Models\VerificationVisit;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Carbon\CarbonImmutable;

class VerificacionVisitaTest extends Modulo5TestCase
{
    public function test_listado_asignado_incluye_nombre_sin_serializar_como_expediente_detallado(): void
    {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
        $app->datosPersonales()->create([
            'first_name' => 'Alberto',
            'first_last_name' => 'Trejo',
            'second_last_name' => 'Saucedo',
        ]);
        VerificationVisit::factory()->create([
            'application_id' => $app->id,
            'verifier_id' => $verifier->id,
            'status' => VerificationVisitStatus::ASSIGNED,
        ]);

        $this->actingAsMfaUser($verifier, ['verifier'])
            ->getJson('/api/v1/verification-visits/assigned?page=1&per_page=20')
            ->assertOk()
            ->assertJsonPath('data.0.application.applicant.full_name', 'Alberto Trejo Saucedo')
            ->assertJsonPath('data.0.application.branch.id', (string) $app->branch_id);
    }

    public function test_acceso_exclusivo_a_visitas_asignadas()
    {
        $branchId = Branch::factory()->create()->id;
        $verifierA = User::factory()->create();
        $verifierB = User::factory()->create();

        $visitA = VerificationVisit::factory()->create(['verifier_id' => $verifierA->id]);

        $response = $this->actingAsMfaUser($verifierB, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visitA->id}/start", ['lock_version' => 1]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER');
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

    public function test_no_inicia_visita_antes_del_horario_programado(): void
    {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
        $visit = VerificationVisit::factory()->create([
            'application_id' => $app->id,
            'verifier_id' => $verifier->id,
            'status' => VerificationVisitStatus::ASSIGNED,
            'scheduled_for' => now()->addDay()->setTime(15, 0),
        ]);

        $this->actingAsMfaUser($verifier, ['verifier'])
            ->postJson("/api/v1/verification-visits/{$visit->id}/start", ['lock_version' => $visit->lock_version])
            ->assertConflict()
            ->assertJsonPath('error.code', 'VERIFICATION_VISIT_OUTSIDE_SCHEDULE');

        $this->assertSame(VerificationVisitStatus::ASSIGNED, $visit->refresh()->status);
    }

    public function test_visita_puede_iniciarse_quince_minutos_antes_y_mas_tarde_el_mismo_dia(): void
    {
        $scheduled = CarbonImmutable::parse('2026-08-24 13:00:00', 'America/Monterrey');

        foreach ([$scheduled->subMinutes(15), $scheduled->addHours(6)] as $startAt) {
            $verifier = User::factory()->create();
            $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
            $visit = VerificationVisit::factory()->create([
                'application_id' => $app->id,
                'verifier_id' => $verifier->id,
                'status' => VerificationVisitStatus::ASSIGNED,
                'scheduled_for' => $scheduled,
            ]);

            $this->travelTo($startAt);
            $this->actingAsMfaUser($verifier, ['verifier'])
                ->postJson("/api/v1/verification-visits/{$visit->id}/start", ['lock_version' => $visit->lock_version])
                ->assertOk();
        }
    }

    public function test_visita_asignada_muestra_identificadores_completos_solo_en_su_expediente(): void
    {
        $verifier = User::factory()->create();
        $app = DistributorApplication::factory()->create(['status' => ApplicationStatus::VERIFIER_ASSIGNED]);
        $protector = app(ProtectorDatosSolicitud::class);
        $personal = new DatosPersonalesSolicitud([
            'application_id' => $app->id,
            'first_name' => 'Alberto',
            'first_last_name' => 'Trejo',
            'nationality' => 'MEXICAN',
            'birth_country' => 'MX',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Matamoros',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Matamoros',
            'email' => 'alberto.verificacion@example.test',
            'phone_number' => '8710000000',
            'identification_country' => 'MX',
            'official_id_type' => 'INE',
        ]);
        $personal->forceFill([
            'curp_ciphertext' => $protector->cifrarCurp('PEAA900101MDFRRN01'),
            'curp_hmac' => $protector->generarHmacCurp('PEAA900101MDFRRN01'),
            'rfc_ciphertext' => $protector->cifrarRfc('PEAA900101ABC'),
            'rfc_hmac' => $protector->generarHmacRfc('PEAA900101ABC'),
        ])->save();
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'verifier_id' => $verifier->id]);

        $this->actingAsMfaUser($verifier, ['verifier'])
            ->getJson("/api/v1/verification-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.application.personal_data.curp', 'PEAA900101MDFRRN01')
            ->assertJsonPath('data.application.personal_data.rfc', 'PEAA900101ABC');
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
        $audit = AuditLog::query()->where('event_name', 'VERIFICATION_DIFFERENCE_RECORDED')->where('entity_id', $visit->id)->firstOrFail();
        $this->assertSame('first_name', $audit->new_value['changes'][0]['field']);
        $this->assertSame('Nombre declarado', $audit->new_value['changes'][0]['before']);
        $this->assertSame('Nombre observado', $audit->new_value['changes'][0]['after']);
    }

    public function test_rechaza_valor_numerico_invalido_en_diferencias(): void
    {
        $verifier = User::factory()->create();
        $visit = VerificationVisit::factory()->create([
            'verifier_id' => $verifier->id,
            'status' => VerificationVisitStatus::IN_PROGRESS,
        ]);

        $this->actingAsMfaUser($verifier, ['verifier'])
            ->putJson("/api/v1/verification-visits/{$visit->id}/differences", [
                'differences_payload' => [
                    'has_differences' => true,
                    'items' => [[
                        'section' => 'assets_liabilities',
                        'field' => 'amount',
                        'declared_value' => '100.0000',
                        'observed_value' => '100abc',
                        'description' => 'El monto observado no coincide.',
                    ]],
                ],
                'lock_version' => $visit->lock_version,
            ])
            ->assertUnprocessable();

        $this->assertNull($visit->refresh()->differences_payload);
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
        $vehicle = VehiculoSolicitud::query()->create([
            'application_id' => $app->id,
            'vehicle_type' => 'SUV',
            'brand' => 'Audi',
            'model' => 'R8',
        ]);
        $declaredMedia = MediaFile::factory()->create([
            'file_type' => 'VEHICLE_EVIDENCE',
            'original_name' => 'titulo-vehiculo.webp',
            'mime_type' => 'image/webp',
        ]);
        MediaFileBinding::query()->create([
            'media_file_id' => $declaredMedia->id,
            'owner_type' => 'application_vehicle',
            'owner_id' => $vehicle->id,
            'purpose' => 'VEHICLE_EVIDENCE',
            'created_by' => $verifier->id,
        ]);

        $this->actingAsMfaUser($verifier, ['verifier'])
            ->getJson("/api/v1/verification-visits/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.application.id', (string) $app->id)
            ->assertJsonPath('data.application.application_number', $app->application_number)
            ->assertJsonPath('data.declared_media_files.0.id', (string) $declaredMedia->id)
            ->assertJsonPath('data.declared_media_files.0.original_name', 'titulo-vehiculo.webp');
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
