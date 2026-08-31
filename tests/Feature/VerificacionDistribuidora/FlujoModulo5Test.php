<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationCorrection;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DistributorApplication;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\VerificationVisit;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FlujoModulo5Test extends Modulo5TestCase
{
    public function test_asigna_verificador_de_la_misma_sucursal_y_audita(): void
    {
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $verifier->id,
                'scheduled_for' => now()->addDay()->setTime(15, 0)->toIso8601String(),
                'lock_version' => $application->lock_version,
            ]);

        $response->assertOk();
        $this->assertSame(ApplicationStatus::VERIFIER_ASSIGNED, $application->refresh()->status);
        $this->assertDatabaseHas('verification_visits', [
            'application_id' => $application->id,
            'verifier_id' => $verifier->id,
            'status' => VerificationVisitStatus::ASSIGNED->value,
        ]);
        $this->assertNotNull(VerificationVisit::query()->where('application_id', $application->id)->value('scheduled_for'));
        $this->assertDatabaseHas('audit_logs', [
            'event_name' => 'VERIFICATION_VISIT_ASSIGNED',
            'actor_id' => $coordinator->id,
        ]);
    }

    public function test_rechaza_verificador_de_otra_sucursal_y_usuario_sin_permiso(): void
    {
        [$application, $coordinator, , $branchId] = $this->expediente();
        $otherVerifier = User::factory()->create();
        $otherVerifier->assignRole('verifier', $this->crearSucursal());

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $otherVerifier->id,
                'scheduled_for' => now()->addDay()->setTime(15, 0)->toIso8601String(),
                'lock_version' => $application->lock_version,
            ]);
        $response->assertForbidden()
            ->assertJsonPath('error.code', 'VERIFIER_BRANCH_MISMATCH');

        $plainUser = User::factory()->create();
        $this->actingAsMfaUser($plainUser)
            ->getJson('/api/v1/distributor-applications')
            ->assertForbidden();
    }

    public function test_verificador_solo_puede_trabajar_su_visita_asignada(): void
    {
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $other = User::factory()->create();

        $this->actingAsMfaUser($other, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/start", [
                'lock_version' => $visit->lock_version,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'VERIFICATION_VISIT_NOT_ASSIGNED_TO_USER');

        $this->assertDatabaseHas('verification_visits', [
            'id' => $visit->id,
            'status' => VerificationVisitStatus::ASSIGNED->value,
        ]);
    }

    public function test_capturista_no_puede_ser_asignado_como_verificador(): void
    {
        [$application, $coordinator, , $branchId] = $this->expediente();
        $submitter = User::query()->findOrFail($application->created_by);
        $submitter->assignRole('verifier', $branchId);

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $submitter->id,
                'scheduled_for' => now()->addDay()->setTime(15, 0)->toIso8601String(),
                'lock_version' => $application->lock_version,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SEGREGATION_OF_DUTIES_VIOLATION');

        $this->assertDatabaseMissing('verification_visits', ['application_id' => $application->id]);
    }

    public function test_flujo_favorable_termina_solo_en_autorizacion_formal(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $manager = User::factory()->create();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciarYDocumentarSinDiferencias($visit, $verifier);

        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'result' => 'FAVORABLE',
                'observations' => 'La información coincide.',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'visit_id' => $visit->id,
                'result' => 'COMPLIES',
                'reason' => 'Expediente y visita conformes.',
                'lock_version' => $application->lock_version,
            ])->assertOk();
        $this->assertSame(ApplicationStatus::MANAGER_AUTHORIZATION, $application->refresh()->status);

        $application->refresh();
        $response = $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => '10000.0000',
                'reason' => 'Expediente aprobado formalmente.',
                'lock_version' => $application->lock_version,
            ]);

        $response->assertOk()->assertJsonPath('data.decision', 'APPROVED');
        $this->assertSame(ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION, $application->refresh()->status);
        $this->assertDatabaseHas('application_authorizations', [
            'application_id' => $application->id,
            'decision' => 'APPROVED',
            'initial_credit_line_amount' => '10000.0000',
        ]);
        $this->assertDatabaseMissing('distributors', ['application_id' => $application->id]);
    }

    public function test_visita_desfavorable_termina_y_no_admite_evaluacion(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciarYDocumentarSinDiferencias($visit, $verifier);

        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'result' => 'UNFAVORABLE',
                'observations' => 'No fue posible validar el domicilio.',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::TERMINATED_UNFAVORABLE, $application->status);
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'visit_id' => $visit->id,
                'result' => 'COMPLIES',
                'reason' => 'Intento inválido.',
                'lock_version' => $application->lock_version,
            ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_INVALID_STATE');
    }

    public function test_correcciones_conservan_original_e_historial_completo(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciar($visit, $verifier, $branchId);
        $visit->refresh();

        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->putJson("/api/v1/verification-visits/{$visit->id}/differences", [
                'differences_payload' => [
                    'has_differences' => true,
                    'items' => [[
                        'section' => 'personal_info',
                        'field' => 'first_name',
                        'declared_value' => 'Ana',
                        'observed_value' => 'Ana María',
                        'description' => 'El nombre completo no coincide.',
                    ]],
                ],
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $this->subirEvidencia($visit, $verifier, $branchId);
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'result' => 'FAVORABLE',
                'observations' => 'Favorable con diferencia corregible.',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/corrections/finish", [
                'lock_version' => $application->lock_version,
            ])->assertConflict()->assertJsonPath('error.code', 'APPLICATION_CORRECTIONS_PENDING');

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/corrections", [
                'section' => 'personal_info',
                'field_path' => 'first_name',
                'new_value' => 'Ana María',
                'reason' => 'Se confirma el nombre observado.',
                'visit_id' => $visit->id,
                'difference_index' => 0,
                'lock_version' => $application->lock_version,
            ])->assertCreated();

        $this->assertSame('Ana María', $application->datosPersonales()->firstOrFail()->first_name);
        $correction = ApplicationCorrection::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertSame(['value' => 'Ana'], $correction->previous_value_payload);
        $this->assertSame(['value' => 'Ana María'], $correction->new_value_payload);
        $audit = AuditLog::query()->where('event_name', 'APPLICATION_CORRECTION_APPLIED')->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame('first_name', $audit->new_value['changes'][0]['field']);
        $this->assertSame('Ana', $audit->new_value['changes'][0]['before']);
        $this->assertSame('Ana María', $audit->new_value['changes'][0]['after']);
        $this->assertDatabaseHas('audit_logs', [
            'event_name' => 'APPLICATION_CORRECTION_APPLIED',
            'entity_id' => $correction->id,
        ]);
    }

    public function test_expediente_canonico_no_conserva_la_raiz_legacy_duplicada(): void
    {
        [$application] = $this->expediente();

        $this->assertFalse(Schema::hasTable('distributor_applications_m5'));
        $this->assertDatabaseHas('application_personal_data', [
            'application_id' => $application->id,
            'first_name' => 'Ana',
        ]);
    }

    public function test_evaluacion_desfavorable_termina_sin_autorizacion(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciarYDocumentarSinDiferencias($visit, $verifier);
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'result' => 'FAVORABLE',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'visit_id' => $visit->id,
                'result' => 'DOES_NOT_COMPLY',
                'reason' => 'El expediente no cumple criterios.',
                'lock_version' => $application->lock_version,
            ])->assertOk();
        $this->assertSame(ApplicationStatus::TERMINATED_UNFAVORABLE, $application->refresh()->status);

        $this->assertDatabaseMissing('application_authorizations', ['application_id' => $application->id]);
    }

    public function test_otro_coordinador_no_puede_evaluar_fuera_de_su_alcance(): void
    {
        [$application, , , $branchId] = $this->expedienteListoParaEvaluar();
        $visit = VerificationVisit::query()->where('application_id', $application->id)->latest()->firstOrFail();
        $otherCoordinator = User::factory()->create();

        $this->actingAsMfaUser($otherCoordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'visit_id' => $visit->id,
                'result' => 'COMPLIES',
                'reason' => 'Intento fuera de alcance.',
                'lock_version' => $application->lock_version,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_no_permite_saltar_de_revision_a_autorizacion(): void
    {
        [$application, , , $branchId] = $this->expediente();
        $manager = User::factory()->create();

        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => '10000.0000',
                'reason' => 'Intento de omitir etapas.',
                'lock_version' => $application->lock_version,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION');
    }

    public function test_gerente_de_otra_sucursal_y_participante_no_pueden_autorizar(): void
    {
        [$application, $coordinator, $verifier, $branchId] = $this->expedienteListoParaAutorizar();
        $otherManager = User::factory()->create();

        $this->actingAsMfaUser($otherManager, ['branch_manager'], $this->crearSucursal())
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => '10000.0000',
                'reason' => 'Fuera de alcance.',
                'lock_version' => $application->lock_version,
            ])->assertForbidden()->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');

        $this->actingAsMfaUser($coordinator, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => '10000.0000',
                'reason' => 'Participó como coordinador.',
                'lock_version' => $application->lock_version,
            ])->assertForbidden()->assertJsonPath('error.code', 'SEGREGATION_OF_DUTIES_VIOLATION');

        $this->assertDatabaseMissing('application_authorizations', ['application_id' => $application->id]);
    }

    public function test_rechazo_es_final_y_no_se_puede_dictaminar_dos_veces(): void
    {
        [$application, , , $branchId] = $this->expedienteListoParaAutorizar();
        $manager = User::factory()->create();

        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'REJECTED',
                'reason' => 'No se autoriza el expediente.',
                'lock_version' => $application->lock_version,
            ])->assertOk()->assertJsonPath('data.decision', 'REJECTED');
        $this->assertSame(ApplicationStatus::REJECTED, $application->refresh()->status);

        $application->refresh();
        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => '10000.0000',
                'reason' => 'Segundo intento.',
                'lock_version' => $application->lock_version,
            ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION');

        $this->assertSame(1, ApplicationAuthorization::query()->where('application_id', $application->id)->count());
    }

    public function test_gerente_general_autoriza_con_alcance_global(): void
    {
        [$application] = $this->expedienteListoParaAutorizar();
        $generalManager = User::factory()->create();

        $this->actingAsMfaUser($generalManager, ['general_manager'])
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'APPROVED',
                'initial_credit_line_amount' => '10000.0000',
                'reason' => 'Expediente autorizado con alcance global.',
                'lock_version' => $application->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('data.decision', 'APPROVED');
    }

    public function test_evidencia_retirada_conserva_archivo_metadatos_y_auditoria(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciar($visit, $verifier, $branchId);
        $this->subirEvidencia($visit, $verifier, $branchId);

        $visit->refresh();
        $media = $visit->mediaFiles()->firstOrFail();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->deleteJson("/api/v1/verification-evidences/{$media->id}")
            ->assertOk();

        Storage::disk('local')->assertMissing($media->path);
        $this->assertNotNull(MediaFile::withTrashed()->findOrFail($media->id)->deleted_at);
        $this->assertDatabaseHas('audit_logs', [
            'event_name' => 'VERIFICATION_EVIDENCE_REMOVED',
            'entity_id' => $media->id,
        ]);
    }

    public function test_previsualizar_evidencia_no_registra_descarga_auditada(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciar($visit, $verifier, $branchId);
        $this->subirEvidencia($visit, $verifier, $branchId);

        $media = $visit->refresh()->mediaFiles()->firstOrFail();
        $before = AuditLog::query()->where('event_name', 'VERIFICATION_EVIDENCE_DOWNLOADED')->count();

        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->get("/api/v1/verification-evidences/{$media->id}/preview")
            ->assertOk();

        $this->assertSame($before, AuditLog::query()->where('event_name', 'VERIFICATION_EVIDENCE_DOWNLOADED')->count());
    }

    private function expediente(): array
    {
        $coordinator = User::factory()->create();
        $branchId = $this->crearSucursal($coordinator);
        $verifier = User::factory()->create();
        $verifier->assignRole('verifier', $branchId);
        $application = DistributorApplication::factory()->create([
            'branch_id' => $branchId,
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_REVIEW,
        ]);
        $protector = app(ProtectorDatosSolicitud::class);
        $personalData = new DatosPersonalesSolicitud([
            'application_id' => $application->id,
            'first_name' => 'Ana',
            'first_last_name' => 'Pérez',
            'nationality' => 'MEXICAN',
            'birth_country' => 'MX',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Torreón',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'email' => 'ana.perez@example.test',
            'phone_number' => '8710000000',
            'identification_country' => 'MX',
            'official_id_type' => 'INE',
        ]);
        $personalData->forceFill([
            'curp_ciphertext' => $protector->cifrarCurp('PEAA900101MDFRRN01'),
            'curp_hmac' => $protector->generarHmacCurp('PEAA900101MDFRRN01'),
            'rfc_ciphertext' => $protector->cifrarRfc('PEAA900101ABC'),
            'rfc_hmac' => $protector->generarHmacRfc('PEAA900101ABC'),
            'official_id_number_ciphertext' => $protector->cifrarIdentificacion('INE-TEST-001'),
            'official_id_number_hmac' => $protector->generarHmacIdentificacion('INE-TEST-001'),
        ])->save();

        return [$application, $coordinator, $verifier, $branchId];
    }

    private function asignar(
        DistributorApplication $application,
        User $coordinator,
        User $verifier,
        string $branchId,
    ): VerificationVisit {
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $verifier->id,
                'scheduled_for' => now()->addDay()->setTime(15, 0)->toIso8601String(),
                'lock_version' => $application->lock_version,
            ])->assertOk();

        return VerificationVisit::query()->where('application_id', $application->id)->firstOrFail();
    }

    private function iniciar(VerificationVisit $visit, User $verifier, string $branchId): void
    {
        $this->travelTo($visit->scheduled_for);
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/start", [
                'lock_version' => $visit->lock_version,
            ])->assertOk();
        $this->travelBack();
    }

    private function subirEvidencia(VerificationVisit $visit, User $verifier, string $branchId): void
    {
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->post("/api/v1/verification-visits/{$visit->id}/evidences", [
                'file' => UploadedFile::fake()->create('domicilio.jpg', 100, 'image/jpeg'),
                'file_type' => 'RESIDENCE_EXTERIOR',
                'lock_version' => $visit->lock_version,
            ])->assertCreated();
    }

    private function iniciarYDocumentarSinDiferencias(VerificationVisit $visit, User $verifier): void
    {
        $branchId = $visit->application->branch_id;
        $this->iniciar($visit, $verifier, $branchId);
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->putJson("/api/v1/verification-visits/{$visit->id}/differences", [
                'differences_payload' => [
                    'has_differences' => false,
                    'items' => [],
                ],
                'lock_version' => $visit->lock_version,
            ])->assertOk();
        $this->subirEvidencia($visit, $verifier, $branchId);
    }

    private function expedienteListoParaAutorizar(): array
    {
        [$application, $coordinator, $verifier, $branchId] = $this->expedienteListoParaEvaluar();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'visit_id' => VerificationVisit::query()->where('application_id', $application->id)->latest()->value('id'),
                'result' => 'COMPLIES',
                'reason' => 'Cumple.',
                'lock_version' => $application->lock_version,
            ])->assertOk();
        $application->refresh();

        return [$application, $coordinator, $verifier, $branchId];
    }

    private function expedienteListoParaEvaluar(): array
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciarYDocumentarSinDiferencias($visit, $verifier);
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'result' => 'FAVORABLE',
                'lock_version' => $visit->lock_version,
            ])->assertOk();
        $application->refresh();

        return [$application, $coordinator, $verifier, $branchId];
    }

    private function crearSucursal(?User $creator = null): string
    {
        $creator ??= User::factory()->create();

        return Branch::query()->create([
            'code' => 'M05-'.Str::upper(Str::random(8)),
            'name' => 'Sucursal M05',
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'created_by' => $creator->id,
        ])->id;
    }
}
