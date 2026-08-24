<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Enums\ApplicationStatus;
use App\Models\CreditoComercialSolicitud;
use App\Models\DistributorApplication;
use App\Models\FamiliarSolicitud;
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
}
