<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitStatus;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationCorrection;
use App\Models\Branch;
use App\Models\DistributorApplication;
use App\Models\MediaFile;
use App\Models\User;
use App\Models\VerificationVisit;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
                'lock_version' => $application->lock_version,
            ]);

        $response->assertOk()->assertJsonPath('data.estado', ApplicationStatus::VERIFIER_ASSIGNED->value);
        $this->assertDatabaseHas('verification_visits', [
            'application_id' => $application->id,
            'verifier_id' => $verifier->id,
            'status' => VerificationVisitStatus::ASSIGNED->value,
        ]);
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
                'lock_version' => $application->lock_version,
            ]);
        $response->assertForbidden()
            ->assertJsonPath('error', 'VERIFIER_BRANCH_MISMATCH');

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
            ->assertJsonPath('error', 'AUTH_SCOPE_DENIED');

        $this->assertDatabaseHas('verification_visits', [
            'id' => $visit->id,
            'status' => VerificationVisitStatus::ASSIGNED->value,
        ]);
    }

    public function test_capturista_no_puede_ser_asignado_como_verificador(): void
    {
        [$application, $coordinator, , $branchId] = $this->expediente();
        $submitter = $application->submitter()->firstOrFail();
        $submitter->assignRole('verifier', $branchId);

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/assign-verifier", [
                'verifier_id' => $submitter->id,
                'lock_version' => $application->lock_version,
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'SEGREGATION_OF_DUTIES_VIOLATION');

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
                'resultado_fisico' => 'FAVORABLE',
                'observaciones' => 'La información coincide.',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'dictamen' => 'COMPLIES',
                'motivo' => 'Expediente y visita conformes.',
                'lock_version' => $application->lock_version,
            ])->assertOk()->assertJsonPath('data.estado', ApplicationStatus::MANAGER_AUTHORIZATION->value);

        $application->refresh();
        $response = $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'AUTORIZADA',
                'motivo' => 'Expediente aprobado formalmente.',
                'lock_version' => $application->lock_version,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.estado', 'AUTORIZADA')
            ->assertJsonPath('data.autorizacion.decision', 'AUTORIZADA');
        $this->assertDatabaseHas('application_authorizations', [
            'application_id' => $application->id,
            'decision' => 'APPROVED',
            'initial_credit_line_amount' => null,
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
                'resultado_fisico' => 'UNFAVORABLE',
                'observaciones' => 'No fue posible validar el domicilio.',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->assertSame(ApplicationStatus::TERMINATED_UNFAVORABLE, $application->status);
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'dictamen' => 'COMPLIES',
                'motivo' => 'Intento inválido.',
                'lock_version' => $application->lock_version,
            ])->assertConflict()->assertJsonPath('error', 'DISTRIBUTOR_APPLICATION_INVALID_STATE');
    }

    public function test_correcciones_conservan_original_e_historial_completo(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciar($visit, $verifier, $branchId);
        $visit->refresh();

        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->putJson("/api/v1/verification-visits/{$visit->id}", [
                'diferencias' => [[
                    'seccion' => 'personal_info',
                    'campo' => 'first_name',
                    'dato_declarado' => 'Ana',
                    'dato_observado' => 'Ana María',
                    'descripcion' => 'El nombre completo no coincide.',
                ]],
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $this->subirEvidencia($visit, $verifier, $branchId);
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/finish", [
                'resultado_fisico' => 'FAVORABLE',
                'observaciones' => 'Favorable con diferencia corregible.',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/corrections/finish", [
                'lock_version' => $application->lock_version,
            ])->assertConflict()->assertJsonPath('error', 'APPLICATION_CORRECTIONS_PENDING');

        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/corrections", [
                'seccion' => 'personal_info',
                'campo' => 'first_name',
                'valor_observado' => 'Ana María',
                'valor_corregido' => 'Ana María',
                'motivo' => 'Se confirma el nombre observado.',
                'lock_version' => $application->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->assertSame('Ana', $application->original_applicant_data['personal_info']['first_name']);
        $this->assertSame('Ana María', $application->applicant_data['personal_info']['first_name']);
        $correction = ApplicationCorrection::query()->where('application_id', $application->id)->firstOrFail();
        $this->assertSame('Ana', $correction->previous_value_payload);
        $this->assertSame('Ana María', $correction->new_value_payload);
        $this->assertDatabaseHas('audit_logs', [
            'event_name' => 'APPLICATION_CORRECTION_APPLIED',
            'entity_id' => $correction->id,
        ]);
    }

    public function test_datos_originales_no_se_pueden_sobrescribir(): void
    {
        [$application] = $this->expediente();

        $this->expectException(QueryException::class);
        DB::table('distributor_applications_m5')
            ->where('id', $application->id)
            ->update(['original_applicant_data' => json_encode(['alterado' => true])]);
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
                'resultado_fisico' => 'FAVORABLE',
                'lock_version' => $visit->lock_version,
            ])->assertOk();

        $application->refresh();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'dictamen' => 'DOES_NOT_COMPLY',
                'motivo' => 'El expediente no cumple criterios.',
                'lock_version' => $application->lock_version,
            ])->assertOk()->assertJsonPath('data.estado', ApplicationStatus::TERMINATED_UNFAVORABLE->value);

        $this->assertDatabaseMissing('application_authorizations', ['application_id' => $application->id]);
    }

    public function test_otro_coordinador_no_puede_evaluar_fuera_de_su_alcance(): void
    {
        [$application, , , $branchId] = $this->expedienteListoParaEvaluar();
        $otherCoordinator = User::factory()->create();

        $this->actingAsMfaUser($otherCoordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'dictamen' => 'COMPLIES',
                'motivo' => 'Intento fuera de alcance.',
                'lock_version' => $application->lock_version,
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'AUTH_SCOPE_DENIED');
    }

    public function test_no_permite_saltar_de_revision_a_autorizacion(): void
    {
        [$application, , , $branchId] = $this->expediente();
        $manager = User::factory()->create();

        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'AUTORIZADA',
                'motivo' => 'Intento de omitir etapas.',
                'lock_version' => $application->lock_version,
            ])
            ->assertConflict()
            ->assertJsonPath('error', 'DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION');
    }

    public function test_gerente_de_otra_sucursal_y_participante_no_pueden_autorizar(): void
    {
        [$application, $coordinator, $verifier, $branchId] = $this->expedienteListoParaAutorizar();
        $otherManager = User::factory()->create();

        $this->actingAsMfaUser($otherManager, ['branch_manager'], $this->crearSucursal())
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'AUTORIZADA',
                'motivo' => 'Fuera de alcance.',
                'lock_version' => $application->lock_version,
            ])->assertForbidden()->assertJsonPath('error', 'AUTH_SCOPE_DENIED');

        $this->actingAsMfaUser($coordinator, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'AUTORIZADA',
                'motivo' => 'Participó como coordinador.',
                'lock_version' => $application->lock_version,
            ])->assertForbidden()->assertJsonPath('error', 'SEGREGATION_OF_DUTIES_VIOLATION');

        $this->assertDatabaseMissing('application_authorizations', ['application_id' => $application->id]);
    }

    public function test_rechazo_es_final_y_no_se_puede_dictaminar_dos_veces(): void
    {
        [$application, , , $branchId] = $this->expedienteListoParaAutorizar();
        $manager = User::factory()->create();

        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'RECHAZADA',
                'motivo' => 'No se autoriza el expediente.',
                'lock_version' => $application->lock_version,
            ])->assertOk()->assertJsonPath('data.estado', 'RECHAZADA');

        $application->refresh();
        $this->actingAsMfaUser($manager, ['branch_manager'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'AUTORIZADA',
                'motivo' => 'Segundo intento.',
                'lock_version' => $application->lock_version,
            ])->assertConflict()->assertJsonPath('error', 'DISTRIBUTOR_APPLICATION_NOT_READY_FOR_AUTHORIZATION');

        $this->assertSame(1, ApplicationAuthorization::query()->where('application_id', $application->id)->count());
    }

    public function test_gerente_general_autoriza_con_alcance_global(): void
    {
        [$application] = $this->expedienteListoParaAutorizar();
        $generalManager = User::factory()->create();

        $this->actingAsMfaUser($generalManager, ['general_manager'])
            ->postJson("/api/v1/distributor-applications/{$application->id}/authorize", [
                'decision' => 'AUTORIZADA',
                'motivo' => 'Expediente autorizado con alcance global.',
                'lock_version' => $application->lock_version,
            ])
            ->assertOk()
            ->assertJsonPath('data.estado', 'AUTORIZADA');
    }

    public function test_evidencia_retirada_conserva_archivo_metadatos_y_auditoria(): void
    {
        Storage::fake('local');
        [$application, $coordinator, $verifier, $branchId] = $this->expediente();
        $visit = $this->asignar($application, $coordinator, $verifier, $branchId);
        $this->iniciar($visit, $verifier, $branchId);
        $this->subirEvidencia($visit, $verifier, $branchId);

        $visit->refresh();
        $media = MediaFile::query()->where('verification_visit_id', $visit->id)->firstOrFail();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->deleteJson("/api/v1/verification-visits/{$visit->id}/evidences/{$media->id}", [
                'lock_version' => $visit->lock_version,
            ])->assertNoContent();

        Storage::disk('local')->assertExists($media->path);
        $this->assertNotNull(MediaFile::withTrashed()->findOrFail($media->id)->deleted_at);
        $this->assertDatabaseHas('audit_logs', [
            'event_name' => 'VERIFICATION_EVIDENCE_ARCHIVED',
            'entity_id' => $media->id,
        ]);
    }

    private function expediente(): array
    {
        $coordinator = User::factory()->create();
        $branchId = $this->crearSucursal($coordinator);
        $verifier = User::factory()->create();
        $verifier->assignRole('verifier', $branchId);
        $data = ['personal_info' => [
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'curp' => 'PEAA900101MDFRRN01',
            'rfc' => 'PEAA900101ABC',
        ]];
        $application = DistributorApplication::factory()->create([
            'branch_id' => $branchId,
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_REVIEW,
            'applicant_data' => $data,
            'original_applicant_data' => $data,
        ]);

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
                'lock_version' => $application->lock_version,
            ])->assertOk();

        return VerificationVisit::query()->where('application_id', $application->id)->firstOrFail();
    }

    private function iniciar(VerificationVisit $visit, User $verifier, string $branchId): void
    {
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->postJson("/api/v1/verification-visits/{$visit->id}/start", [
                'lock_version' => $visit->lock_version,
            ])->assertOk();
    }

    private function subirEvidencia(VerificationVisit $visit, User $verifier, string $branchId): void
    {
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->post("/api/v1/verification-visits/{$visit->id}/evidences", [
                'file' => UploadedFile::fake()->create('domicilio.jpg', 100, 'image/jpeg'),
                'tipo' => 'RESIDENCE_EXTERIOR',
                'lock_version' => $visit->lock_version,
            ])->assertCreated();
    }

    private function iniciarYDocumentarSinDiferencias(VerificationVisit $visit, User $verifier): void
    {
        $branchId = $visit->application->branch_id;
        $this->iniciar($visit, $verifier, $branchId);
        $visit->refresh();
        $this->actingAsMfaUser($verifier, ['verifier'], $branchId)
            ->putJson("/api/v1/verification-visits/{$visit->id}", [
                'observaciones_generales' => 'Visita documentada.',
                'diferencias' => [],
                'lock_version' => $visit->lock_version,
            ])->assertOk();
        $this->subirEvidencia($visit, $verifier, $branchId);
    }

    private function expedienteListoParaAutorizar(): array
    {
        [$application, $coordinator, $verifier, $branchId] = $this->expedienteListoParaEvaluar();
        $this->actingAsMfaUser($coordinator, ['coordinator'], $branchId)
            ->postJson("/api/v1/distributor-applications/{$application->id}/evaluate", [
                'dictamen' => 'COMPLIES',
                'motivo' => 'Cumple.',
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
                'resultado_fisico' => 'FAVORABLE',
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
