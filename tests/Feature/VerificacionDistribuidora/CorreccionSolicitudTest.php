<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Models\CreditoComercialSolicitud;
use App\Models\DatosPersonalesSolicitud;
use App\Models\DistributorApplication;
use App\Models\FamiliarSolicitud;
use App\Models\PatrimonioSolicitud;
use App\Models\User;
use App\Models\VerificationVisit;
use App\Services\SolicitudDistribuidora\ProtectorDatosSolicitud;

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
                'visit_id' => $visit->id, 'section' => 'personal_info', 'field_path' => 'first_name', 'new_value' => 'New', 'reason' => 'Fix', 'lock_version' => 1, 'difference_index' => 0,
            ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
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

        $response->assertStatus(409)->assertJsonPath('error.code', 'APPLICATION_CORRECTIONS_PENDING');
    }

    public function test_corrige_el_registro_familiar_seleccionado_sin_afectar_otro(): void
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create(['coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_CORRECTION]);
        $primero = FamiliarSolicitud::query()->create(['application_id' => $app->id, 'relationship' => 'CHILD', 'first_name' => 'Ana', 'school_name' => null]);
        $segundo = FamiliarSolicitud::query()->create(['application_id' => $app->id, 'relationship' => 'CHILD', 'first_name' => 'Luis', 'school_name' => null]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'differences_payload' => ['items' => [
            ['section' => 'family_members', 'field' => 'school_name', 'observed_value' => 'Primaria Norte', 'description' => 'Falta escuela'],
            ['section' => 'family_members', 'field' => 'school_name', 'observed_value' => 'Secundaria Central', 'description' => 'Falta escuela'],
        ]]]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id,
                'section' => 'family_members',
                'field_path' => 'school_name',
                'record_id' => $segundo->id,
                'difference_index' => 1,
                'lock_version' => $app->lock_version,
            ]);

        $response->assertCreated()->assertJsonPath('data.target_record_id', $segundo->id)->assertJsonPath('data.difference_index', 1);
        $this->assertNull($primero->fresh()->school_name);
        $this->assertSame('Secundaria Central', $segundo->fresh()->school_name);
    }

    public function test_corrige_y_protege_la_curp_del_expediente(): void
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create([
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_CORRECTION,
        ]);
        $protector = app(ProtectorDatosSolicitud::class);
        $personal = new DatosPersonalesSolicitud;
        $personal->forceFill([
            'application_id' => $app->id,
            'first_name' => 'Ana',
            'first_last_name' => 'Pérez',
            'nationality' => 'MEXICAN',
            'birth_country' => 'MX',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Torreón',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'email' => 'ana.curp@example.test',
            'phone_number' => '8710000000',
            'identification_country' => 'MX',
            'official_id_type' => 'INE',
            'curp_ciphertext' => $protector->cifrarCurp('PEAA900101MDFRRN01'),
            'curp_hmac' => $protector->generarHmacCurp('PEAA900101MDFRRN01'),
            'official_id_number_ciphertext' => $protector->cifrarIdentificacion('INE-TEST-001'),
            'official_id_number_hmac' => $protector->generarHmacIdentificacion('INE-TEST-001'),
        ])->save();
        $visit = VerificationVisit::factory()->create([
            'application_id' => $app->id,
            'differences_payload' => ['items' => [[
                'section' => 'personal_info',
                'field' => 'curp',
                'declared_value' => 'PEAA900101MDFRRN01',
                'observed_value' => 'LUMA900101HDFABC09',
                'description' => 'La CURP física no coincide.',
            ]]],
        ]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id,
                'section' => 'personal_info',
                'field_path' => 'curp',
                'difference_index' => 0,
                'lock_version' => $app->lock_version,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.field_path', 'curp')
            ->assertJsonPath('data.accepted_value', 'LUMA900101HDFABC09');
        self::assertSame('LUMA900101HDFABC09', $protector->descifrar($personal->fresh()->curp_ciphertext));
    }

    public function test_corrige_nacionalidad_con_el_codigo_del_select_canonico(): void
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create([
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_CORRECTION,
        ]);
        $personal = DatosPersonalesSolicitud::query()->create([
            'application_id' => $app->id,
            'first_name' => 'Ana',
            'first_last_name' => 'Perez',
            'nationality' => 'FOREIGN',
            'birth_country' => 'US',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Dallas',
            'birth_state' => 'Texas',
            'birth_city' => 'Dallas',
            'email' => 'ana.nacionalidad@example.test',
            'phone_number' => '+1525550100',
            'identification_country' => 'US',
            'official_id_type' => 'PASSPORT',
        ]);
        $visit = VerificationVisit::factory()->create([
            'application_id' => $app->id,
            'differences_payload' => ['items' => [[
                'section' => 'personal_info',
                'field' => 'nationality',
                'record_id' => null,
                'observed_value' => 'MEXICAN',
                'description' => 'La nacionalidad observada es mexicana.',
            ]]],
        ]);

        $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id,
                'section' => 'personal_info',
                'field_path' => 'nationality',
                'difference_index' => 0,
                'new_value' => 'MEXICAN',
                'lock_version' => $app->lock_version,
            ])
            ->assertCreated();

        $this->assertSame('MEXICAN', $personal->refresh()->nationality);
    }

    public function test_acepta_observacion_de_comprobante_sin_escribir_texto_en_columna_uuid(): void
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create(['coordinator_id' => $coordinator->id, 'status' => ApplicationStatus::COORDINATOR_CORRECTION]);
        $credit = CreditoComercialSolicitud::query()->create(['application_id' => $app->id, 'company_name' => 'Proveedor', 'credit_limit' => 5000, 'is_current' => true]);
        $visit = VerificationVisit::factory()->create(['application_id' => $app->id, 'differences_payload' => ['items' => [[
            'section' => 'commercial_credits', 'field' => 'proof_reference', 'declared_value' => 'Sin dato',
            'observed_value' => 'Comprobante revisado durante la visita', 'description' => 'Se confirmó físicamente.',
        ]]]]);

        $response = $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id, 'section' => 'commercial_credits', 'field_path' => 'proof_reference',
                'record_id' => $credit->id, 'difference_index' => 0, 'lock_version' => $app->lock_version,
            ]);

        $response->assertCreated()->assertJsonPath('data.observed_value', 'Comprobante revisado durante la visita');
        $this->assertNull($credit->fresh()->proof_reference);

        $repeated = $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id, 'section' => 'commercial_credits', 'field_path' => 'proof_reference',
                'record_id' => $credit->id, 'difference_index' => 0, 'lock_version' => $app->lock_version,
            ]);
        $repeated->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertSame(1, $app->corrections()->count());
    }

    public function test_rechaza_letras_en_correccion_numerica_y_no_modifica_expediente(): void
    {
        $coordinator = User::factory()->create();
        $app = DistributorApplication::factory()->create([
            'coordinator_id' => $coordinator->id,
            'status' => ApplicationStatus::COORDINATOR_CORRECTION,
        ]);
        $asset = PatrimonioSolicitud::query()->create([
            'application_id' => $app->id,
            'entry_type' => 'ASSET',
            'name' => 'Equipo',
            'amount' => '100.0000',
            'is_active' => true,
        ]);
        $visit = VerificationVisit::factory()->create([
            'application_id' => $app->id,
            'differences_payload' => ['items' => [[
                'section' => 'assets_liabilities',
                'field' => 'amount',
                'record_id' => $asset->id,
                'observed_value' => '250.0000',
                'description' => 'El monto observado requiere corrección.',
            ]]],
        ]);

        $this->actingAsMfaUser($coordinator, ['coordinator'])
            ->postJson("/api/v1/distributor-applications/{$app->id}/corrections", [
                'visit_id' => $visit->id,
                'section' => 'assets_liabilities',
                'field_path' => 'amount',
                'record_id' => $asset->id,
                'difference_index' => 0,
                'new_value' => '250abc',
                'lock_version' => $app->lock_version,
            ])
            ->assertUnprocessable();

        $this->assertSame('100.0000', $asset->refresh()->amount);
        $this->assertDatabaseCount('application_corrections', 0);
    }
}
