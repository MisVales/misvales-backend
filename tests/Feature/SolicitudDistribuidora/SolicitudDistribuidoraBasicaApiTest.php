<?php

namespace Tests\Feature\SolicitudDistribuidora;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\MediaFile;
use App\Models\MediaFileBinding;
use App\Models\Role;
use App\Models\SolicitudDistribuidora;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SolicitudDistribuidoraBasicaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_el_gerente_general_crea_una_solicitud_en_borrador_con_folio_unico(): void
    {
        $gerente = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($gerente, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        Sanctum::actingAs($gerente);

        $respuesta = $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
        ]);

        $respuesta
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.branch.id', $sucursal->id)
            ->assertJsonPath('data.coordinator.id', $coordinador->id)
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.completion.completed_sections', 0)
            ->assertJsonPath('data.completion.total_sections', 2);

        self::assertMatchesRegularExpression('/^SOL-\d{4}-\d{6,}$/', $respuesta->json('data.application_number'));
        $segunda = $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
        ])->assertCreated();
        self::assertNotSame($respuesta->json('data.application_number'), $segunda->json('data.application_number'));
        $this->assertDatabaseHas('distributor_applications', [
            'id' => $respuesta->json('data.id'),
            'created_by' => $gerente->id,
            'status' => 'DRAFT',
        ]);
    }

    public function test_dos_transacciones_concurrentes_reciben_valores_de_folio_distintos(): void
    {
        config(['database.connections.pgsql_solicitudes_concurrentes' => config('database.connections.pgsql')]);
        $principal = DB::connection('pgsql');
        $concurrente = DB::connection('pgsql_solicitudes_concurrentes');

        $valorPrincipal = (int) $principal->selectOne("SELECT nextval('distributor_application_number_seq') AS value")->value;
        $valorConcurrente = (int) $concurrente->selectOne("SELECT nextval('distributor_application_number_seq') AS value")->value;

        self::assertNotSame($valorPrincipal, $valorConcurrente);
        DB::disconnect('pgsql_solicitudes_concurrentes');
    }

    public function test_rechaza_datos_personales_y_referencias_menores_de_edad_con_mensaje_claro(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-18');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100018');
        Sanctum::actingAs($general);
        $menorDeEdad = now()->subYears(18)->addDay()->toDateString();

        $this->putJson("/api/v1/distributor-applications/{$solicitud->id}/personal-data", [
            'lock_version' => 1,
            'nationality' => 'MEXICAN',
            'first_name' => 'Ana',
            'first_last_name' => 'Pérez',
            'curp' => 'GODE561231HDFXXX09',
            'birth_date' => $menorDeEdad,
            'birth_country' => 'MX',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'email' => 'ana@example.test',
            'phone_number' => '8711234567',
            'official_id_type' => 'INE',
            'official_id_number' => 'INE-001',
        ])->assertUnprocessable()
            ->assertJsonPath('error.fields.birth_date.0', 'La persona solicitante debe tener al menos 18 años.');

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/family-members", [
            'lock_version' => 1,
            'relationship' => 'SIBLING',
            'first_name' => 'Luis',
            'first_last_name' => 'Pérez',
            'birth_date' => $menorDeEdad,
        ])->assertUnprocessable()
            ->assertJsonPath('error.fields.birth_date.0', 'La referencia familiar debe tener al menos 18 años.');
    }

    public function test_gerente_de_sucursal_solo_crea_y_lista_en_su_alcance(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $propia = $this->sucursal($general, 'TRC-01');
        $ajena = $this->sucursal($general, 'TRC-02');
        $gerente = $this->usuarioConRol('branch_manager', $propia->id);
        $coordinadorPropio = $this->usuarioConRol('coordinator', $propia->id);
        $coordinadorAjeno = $this->usuarioConRol('coordinator', $ajena->id);
        $visible = $this->solicitud($general, $propia, $coordinadorPropio, 'SOL-2026-100001');
        $this->solicitud($general, $ajena, $coordinadorAjeno, 'SOL-2026-100002');
        Sanctum::actingAs($gerente);

        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);

        $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $ajena->id,
            'coordinator_id' => $coordinadorAjeno->id,
        ])->assertForbidden();
        $this->assertDatabaseHas('security_events', [
            'branch_id' => $ajena->id,
            'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
            'outcome' => 'DENIED',
        ]);

        $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $propia->id,
            'coordinator_id' => $coordinadorPropio->id,
        ])->assertCreated();
    }

    public function test_coordinador_solo_crea_y_modifica_solicitudes_bajo_su_responsabilidad(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $otro = $this->usuarioConRol('coordinator', $sucursal->id);
        $propia = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100003');
        $ajena = $this->solicitud($general, $sucursal, $otro, 'SOL-2026-100004');
        Sanctum::actingAs($coordinador);

        $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
        ])->assertCreated();

        $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $sucursal->id,
            'coordinator_id' => $otro->id,
        ])->assertForbidden();

        $this->patchJson("/api/v1/distributor-applications/{$propia->id}", [
            'lock_version' => 1,
        ])->assertOk()->assertJsonPath('data.lock_version', 2);

        $this->patchJson("/api/v1/distributor-applications/{$ajena->id}", [
            'lock_version' => 1,
        ])->assertForbidden();
    }

    public function test_administrador_es_global_de_solo_lectura_y_cajera_no_tiene_acceso(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100005');
        $admin = $this->usuarioConRol('admin');
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/distributor-applications')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")->assertOk();
        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}", ['lock_version' => 1])->assertForbidden();
        $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
        ])->assertForbidden();

        Sanctum::actingAs($this->usuarioConRol('cashier', $sucursal->id));
        $this->getJson('/api/v1/distributor-applications')->assertForbidden();
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
            'outcome' => 'DENIED',
        ]);

        Sanctum::actingAs($this->usuarioConRol('distributor', $sucursal->id));
        $this->getJson('/api/v1/distributor-applications')->assertForbidden();

        Sanctum::actingAs($this->usuarioConRol('verifier', $sucursal->id));
        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")->assertForbidden();
    }

    public function test_no_acepta_estado_propiedades_desconocidas_ni_version_obsoleta(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100006');
        Sanctum::actingAs($general);

        $this->postJson('/api/v1/distributor-applications', [
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
            'status' => 'ACTIVE',
            'unexpected' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_SECTION_INCOMPLETE')
            ->assertJsonStructure(['error' => ['fields' => ['status', 'unexpected']]]);

        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}", [
            'lock_version' => 99,
        ])->assertConflict()->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');

        $this->deleteJson("/api/v1/distributor-applications/{$solicitud->id}")->assertMethodNotAllowed();
        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}/status", [
            'status' => 'ACTIVE',
        ])->assertNotFound();
    }

    public function test_guarda_datos_personales_cifrados_y_solo_los_expone_enmascarados(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100007');
        Sanctum::actingAs($general);

        $this->putJson("/api/v1/distributor-applications/{$solicitud->id}/personal-data", [
            'lock_version' => 1,
            'nationality' => 'MEXICAN',
            'first_name' => '  maría   fernanda ',
            'first_last_name' => 'pérez',
            'second_last_name' => 'lópez',
            'curp' => 'GODE561231HDFXXX09',
            'rfc' => 'GODE561231GR8',
            'birth_date' => '1990-01-15',
            'birth_country' => 'MX',
            'birth_place' => 'Torreón',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'email' => 'MARIA@EXAMPLE.TEST',
            'phone_number' => '8711234567',
            'official_id_type' => 'INE',
            'official_id_number' => 'ABC123456789',
        ])
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'María Fernanda')
            ->assertJsonPath('data.curp_masked', 'GODE********FXXX09')
            ->assertJsonPath('data.application_lock_version', 2)
            ->assertJsonMissingPath('data.curp_ciphertext');

        $registro = DB::table('application_personal_data')->where('application_id', $solicitud->id)->first();
        self::assertNotSame('GODE561231HDFXXX09', $registro->curp_ciphertext);
        self::assertNotSame('GODE561231GR8', $registro->rfc_ciphertext);
        self::assertNotSame('ABC123456789', $registro->official_id_number_ciphertext);
        self::assertSame(64, strlen($registro->curp_hmac));
        $metadataAuditoria = DB::table('security_events')
            ->where('event_type', 'DISTRIBUTOR_APPLICATION_PERSONAL_DATA_UPDATED')
            ->value('metadata');
        self::assertStringNotContainsString('GODE561231HDFXXX09', (string) $metadataAuditoria);
        self::assertStringNotContainsString('GODE561231GR8', (string) $metadataAuditoria);

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.personal_data.curp_masked', 'GODE********FXXX09')
            ->assertJsonPath('data.personal_data.curp', 'GODE561231HDFXXX09')
            ->assertJsonPath('data.personal_data.rfc', 'GODE561231GR8')
            ->assertJsonPath('data.personal_data.official_id_number', 'ABC123456789')
            ->assertJsonMissingPath('data.personal_data.curp_ciphertext');

        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonPath('data.0.applicant.curp_masked', 'GODE********FXXX09');

        Sanctum::actingAs($this->usuarioConRol('admin'));
        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.personal_data.curp_masked', 'GODE********FXXX09')
            ->assertJsonMissingPath('data.personal_data.curp')
            ->assertJsonMissingPath('data.personal_data.rfc')
            ->assertJsonMissingPath('data.personal_data.official_id_number');
    }

    public function test_autoguardado_de_datos_personales_acepta_un_borrador_incompleto(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100010');
        Sanctum::actingAs($general);

        $this->withHeaders(['X-Autosave' => 'true'])
            ->putJson("/api/v1/distributor-applications/{$solicitud->id}/personal-data", [
                'lock_version' => 1,
                'first_name' => '  ana   maría ',
            ])
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Ana María')
            ->assertJsonPath('data.application_lock_version', 2);

        $this->assertDatabaseHas('application_personal_data', [
            'application_id' => $solicitud->id,
            'first_name' => 'Ana María',
            'first_last_name' => null,
        ]);
    }

    public function test_administra_domicilios_y_rechaza_dos_domicilios_actuales(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100008');
        Sanctum::actingAs($general);
        $domicilio = [
            'lock_version' => 1,
            'is_current' => true,
            'street' => 'Av. Juárez',
            'exterior_number' => '100',
            'neighborhood' => 'Centro',
            'postal_code' => '27000',
            'municipality' => 'Torreón',
            'city' => 'Torreón',
            'state' => 'Coahuila',
            'housing_tenure' => 'OWNED',
            'financing_status' => 'PAID',
            'width_meters' => '10.50',
        ];

        $creado = $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/residences", $domicilio)
            ->assertCreated()
            ->assertJsonPath('data.width_meters', '10.50')
            ->assertJsonPath('data.application_lock_version', 2);

        $domicilio['lock_version'] = 2;
        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/residences", $domicilio)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_CURRENT_RESIDENCE_DUPLICATE');

        $this->deleteJson("/api/v1/distributor-applications/{$solicitud->id}/residences/{$creado->json('data.id')}", [
            'lock_version' => 2,
        ])->assertNoContent();
    }

    public function test_solo_envia_un_expediente_completo_y_lo_bloquea_para_edicion(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100009');
        Sanctum::actingAs($general);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/submit", ['lock_version' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_INCOMPLETE');

        $this->putJson("/api/v1/distributor-applications/{$solicitud->id}/personal-data", [
            'lock_version' => 1,
            'nationality' => 'MEXICAN',
            'first_name' => 'María',
            'first_last_name' => 'Pérez',
            'curp' => 'GODE561231HDFXXX09',
            'birth_date' => '1990-01-15',
            'birth_country' => 'MX',
            'birth_place' => 'Torreón',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'email' => 'maria@example.test',
            'phone_number' => '8711234567',
            'official_id_type' => 'INE',
            'official_id_number' => 'ABC123456789',
        ])->assertCreated();

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/submit", ['lock_version' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_INCOMPLETE');

        $archivo = MediaFile::query()->create([
            'file_type' => 'IDENTIFICATION_EVIDENCE',
            'disk' => 'private',
            'path' => "applications/{$solicitud->id}/identificacion.pdf",
            'original_name' => 'identificacion.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'sha256' => hash('sha256', (string) Str::uuid()),
            'uploaded_by' => $general->id,
            'validation_status' => 'VALIDATED',
            'validated_at' => now(),
        ]);
        MediaFileBinding::query()->create([
            'media_file_id' => $archivo->id,
            'owner_type' => 'distributor_application',
            'owner_id' => $solicitud->id,
            'purpose' => 'IDENTIFICATION',
            'created_by' => $general->id,
        ]);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/residences", [
            'lock_version' => 2,
            'is_current' => true,
            'street' => 'Av. Juárez',
            'exterior_number' => '100',
            'neighborhood' => 'Centro',
            'postal_code' => '27000',
            'municipality' => 'Torreón',
            'city' => 'Torreón',
            'state' => 'Coahuila',
            'housing_tenure' => 'OWNED',
        ])->assertCreated();

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.section_declarations.personal_data', 'COMPLETED')
            ->assertJsonPath('data.section_declarations.residence', 'COMPLETED')
            ->assertJsonPath('data.section_declarations.vehicles', 'NOT_APPLICABLE')
            ->assertJsonPath('data.completion.can_submit', true);

        $usuariosAntes = User::query()->count();
        $invitacionesAntes = DB::table('account_invitations')->count();
        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/submit", ['lock_version' => 3])
            ->assertOk()
            ->assertJsonPath('data.status', 'COORDINATOR_REVIEW')
            ->assertJsonPath('data.lock_version', 4);

        $this->assertDatabaseHas('distributor_applications', [
            'id' => $solicitud->id,
            'status' => 'COORDINATOR_REVIEW',
            'submitted_by' => $general->id,
        ]);
        self::assertNotNull(SolicitudDistribuidora::query()->findOrFail($solicitud->id)->submitted_at);
        self::assertSame($usuariosAntes, User::query()->count());
        self::assertSame($invitacionesAntes, DB::table('account_invitations')->count());
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'DISTRIBUTOR_APPLICATION_SUBMITTED',
            'entity_id' => $solicitud->id,
            'outcome' => 'SUCCESS',
        ]);

        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}", ['lock_version' => 4])
            ->assertConflict()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_EDITABLE');

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/submit", ['lock_version' => 4])
            ->assertConflict()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_ALREADY_SUBMITTED');

        $domicilio = $solicitud->domicilios()->firstOrFail();
        $this->deleteJson("/api/v1/distributor-applications/{$solicitud->id}/residences/{$domicilio->id}", ['lock_version' => 4])
            ->assertConflict()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_EDITABLE');

        $this->assertDatabaseHas('application_residences', ['id' => $domicilio->id]);
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'DISTRIBUTOR_APPLICATION_ACCESS_DENIED',
            'entity_id' => $solicitud->id,
            'outcome' => 'DENIED',
        ]);
    }

    public function test_administra_todas_las_colecciones_del_expediente_con_versionado_y_pertenencia(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100010');
        Sanctum::actingAs($general);

        $familiar = $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/family-members", [
            'lock_version' => 1,
            'relationship' => 'CHILD',
            'first_name' => 'ana',
            'first_last_name' => 'pérez',
            'declared_age' => 10,
            'school_name' => 'Primaria Centro',
            'is_family_reference' => false,
        ])->assertCreated()->assertJsonPath('data.application_lock_version', 2);

        $vehiculo = $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/vehicles", [
            'lock_version' => 2,
            'vehicle_type' => 'MOTORCYCLE',
            'brand' => 'Honda',
            'model_year' => 2022,
        ])->assertCreated()->assertJsonPath('data.application_lock_version', 3);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/assets-liabilities", [
            'lock_version' => 3,
            'entry_type' => 'LIABILITY',
            'name' => 'Préstamo personal',
            'amount' => '12500.5',
            'outstanding_balance' => '8000',
            'monthly_payment' => '750.25',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '12500.5000')
            ->assertJsonPath('data.monthly_payment', '750.2500')
            ->assertJsonPath('data.application_lock_version', 4);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/employments", [
            'lock_version' => 4,
            'employer_name' => 'Comercial del Norte',
            'job_title' => 'Supervisora',
            'started_at' => '2020-01-01',
            'is_current' => true,
            'reference_payload' => ['name' => 'Referencia laboral'],
        ])->assertCreated()->assertJsonPath('data.application_lock_version', 5);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/commercial-credits", [
            'lock_version' => 5,
            'company_name' => 'Vales del Norte',
            'credit_limit' => '20000.75',
            'is_current' => true,
        ])->assertCreated()
            ->assertJsonPath('data.credit_limit', '20000.7500')
            ->assertJsonPath('data.application_lock_version', 6);

        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}/family-members/{$familiar->json('data.id')}", [
            'lock_version' => 6,
            'school_name' => 'Primaria Renovada',
        ])->assertOk()
            ->assertJsonPath('data.school_name', 'Primaria Renovada')
            ->assertJsonPath('data.application_lock_version', 7);

        $this->deleteJson("/api/v1/distributor-applications/{$solicitud->id}/vehicles/{$vehiculo->json('data.id')}", [
            'lock_version' => 7,
        ])->assertNoContent();

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.family_members')
            ->assertJsonCount(0, 'data.vehicles')
            ->assertJsonPath('data.assets_liabilities.0.amount', '12500.5000')
            ->assertJsonPath('data.commercial_credits.0.credit_limit', '20000.7500')
            ->assertJsonMissingPath('data.assets_liabilities.0.application_lock_version');

        $otra = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100011');
        $this->patchJson("/api/v1/distributor-applications/{$otra->id}/family-members/{$familiar->json('data.id')}", [
            'lock_version' => 1,
            'school_name' => 'Intento fuera del expediente',
        ])->assertForbidden();
    }

    public function test_el_resumen_calcula_las_secciones_y_no_acepta_estados_manuales(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100012');
        Sanctum::actingAs($general);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/vehicles", [
            'lock_version' => 1,
            'vehicle_type' => 'BICYCLE',
        ])->assertCreated();

        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}", [
            'lock_version' => 2,
            'section_declarations' => ['vehicles' => 'NOT_APPLICABLE'],
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['section_declarations']]]);

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.section_declarations.vehicles', 'COMPLETED');

        $vehiculo = $solicitud->vehiculos()->firstOrFail();
        $this->deleteJson("/api/v1/distributor-applications/{$solicitud->id}/vehicles/{$vehiculo->id}", [
            'lock_version' => 2,
        ])->assertNoContent();

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.section_declarations.vehicles', 'NOT_APPLICABLE')
            ->assertJsonPath('data.completion.can_submit', false);
    }

    public function test_autoguardado_de_colecciones_acepta_borradores_parciales_con_versionado(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100016');
        Sanctum::actingAs($general);

        $borradores = [
            ['family-members', ['first_name' => 'Ana']],
            ['residences', ['is_current' => false, 'street' => 'Av. Reforma']],
            ['vehicles', ['brand' => 'Honda']],
            ['assets-liabilities', ['name' => 'Casa familiar']],
            ['employments', ['job_title' => 'Supervisora']],
            ['commercial-credits', ['proof_reference' => (string) Str::uuid()]],
        ];

        foreach ($borradores as $indice => [$ruta, $datos]) {
            $this->withHeader('X-Autosave', 'true')
                ->postJson("/api/v1/distributor-applications/{$solicitud->id}/{$ruta}", [
                    'lock_version' => $indice + 1,
                    ...$datos,
                ])
                ->assertCreated()
                ->assertJsonPath('data.application_lock_version', $indice + 2);
        }

        $this->assertDatabaseHas('application_family_members', ['application_id' => $solicitud->id, 'first_name' => 'Ana', 'relationship' => null]);
        $this->assertDatabaseHas('application_residences', ['application_id' => $solicitud->id, 'street' => 'Av. Reforma', 'housing_tenure' => null]);
        $this->assertDatabaseHas('application_vehicles', ['application_id' => $solicitud->id, 'brand' => 'Honda', 'vehicle_type' => null]);
        $this->assertDatabaseHas('application_assets_liabilities', ['application_id' => $solicitud->id, 'name' => 'Casa familiar', 'entry_type' => null]);
        $this->assertDatabaseHas('application_employments', ['application_id' => $solicitud->id, 'job_title' => 'Supervisora', 'employer_name' => null]);
        $this->assertDatabaseHas('application_commercial_credits', ['application_id' => $solicitud->id, 'proof_reference' => $borradores[5][1]['proof_reference'], 'company_name' => null]);

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}")
            ->assertOk()
            ->assertJsonPath('data.section_declarations.family_references', 'PENDING')
            ->assertJsonPath('data.section_declarations.vehicles', 'PENDING')
            ->assertJsonPath('data.section_declarations.assets', 'PENDING')
            ->assertJsonPath('data.section_declarations.employment', 'PENDING')
            ->assertJsonPath('data.section_declarations.commercial_credits', 'PENDING');
    }

    public function test_registra_pareja_multiples_hijos_y_valida_fechas_laborales_en_patch(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100014');
        Sanctum::actingAs($general);

        foreach ([
            ['relationship' => 'PARTNER', 'first_name' => 'Alex', 'first_last_name' => 'López'],
            ['relationship' => 'CHILD', 'first_name' => 'Ana', 'first_last_name' => 'López', 'declared_age' => 8],
            ['relationship' => 'CHILD', 'first_name' => 'Luis', 'first_last_name' => 'López', 'declared_age' => 5],
        ] as $indice => $familiar) {
            $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/family-members", [
                'lock_version' => $indice + 1,
                ...$familiar,
            ])->assertCreated();
        }

        $this->getJson("/api/v1/distributor-applications/{$solicitud->id}/family-members")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        $empleo = $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/employments", [
            'lock_version' => 4,
            'employer_name' => 'Empresa Norte',
            'started_at' => '2024-01-01',
            'ended_at' => '2024-12-31',
            'is_current' => false,
        ])->assertCreated();

        $this->patchJson("/api/v1/distributor-applications/{$solicitud->id}/employments/{$empleo->json('data.id')}", [
            'lock_version' => 5,
            'started_at' => '2025-01-01',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_SECTION_INCOMPLETE')
            ->assertJsonStructure(['error' => ['fields' => ['ended_at']]]);
    }

    public function test_el_envio_rechaza_propiedades_desconocidas(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100015');
        Sanctum::actingAs($general);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/submit", [
            'lock_version' => 1,
            'status' => 'COORDINATOR_REVIEW',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['status']]]);
    }

    public function test_el_listado_aplica_filtros_paginacion_y_ordenamiento_controlado(): void
    {
        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $primera = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-200001');
        $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-200002');
        Sanctum::actingAs($general);

        $this->getJson('/api/v1/distributor-applications?application_number=SOL-2026-200001&status=DRAFT&branch_id='.$sucursal->id.'&coordinator_id='.$coordinador->id.'&created_from='.now()->subDay()->format('Y-m-d').'&created_to='.now()->addDay()->format('Y-m-d').'&sort=application_number&direction=asc&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $primera->id);

        $this->getJson('/api/v1/distributor-applications?sort=created_by')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['sort']]]);
    }

    public function test_contratos_de_seguridad_validacion_y_fuera_de_alcance_del_modulo(): void
    {
        $this->getJson('/api/v1/distributor-applications')->assertUnauthorized();

        $general = $this->usuarioConRol('general_manager');
        $sucursal = $this->sucursal($general, 'TRC-01');
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id);
        $solicitud = $this->solicitud($general, $sucursal, $coordinador, 'SOL-2026-100013');
        Sanctum::actingAs($general);

        $this->getJson('/api/v1/distributor-applications/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_FOUND');

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/vehicles", [
            'lock_version' => 1,
            'vehicle_type' => 'CAR',
            'plate' => 'NO-PERMITIDA',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['plate']]]);

        self::assertTrue(Schema::hasTable('distributors'));
        self::assertTrue(Schema::hasTable('credit_lines'));
        self::assertTrue(Schema::hasTable('distributor_category_assignments'));
    }

    private function usuarioConRol(string $rol, ?string $sucursalId = null): User
    {
        $email = Str::uuid().'@example.test';
        $usuario = User::factory()->create([
            'email' => $email,
            'normalized_email' => $email,
            'state' => 'ACTIVE',
        ]);
        $role = Role::query()->where('code', $rol)->firstOrFail();

        UserRoleScope::query()->create([
            'user_id' => $usuario->id,
            'role_id' => $role->id,
            'branch_id' => $sucursalId,
            'assigned_by_user_id' => $usuario->id,
        ]);

        return $usuario;
    }

    private function sucursal(User $creador, string $codigo): BranchRecord
    {
        return BranchRecord::query()->create([
            'id' => (string) Str::uuid(),
            'code' => $codigo,
            'name' => "Sucursal {$codigo}",
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => 0,
            'created_by' => $creador->id,
        ]);
    }

    private function solicitud(User $creador, BranchRecord $sucursal, User $coordinador, string $folio): SolicitudDistribuidora
    {
        return SolicitudDistribuidora::factory()->create([
            'application_number' => $folio,
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
            'status' => 'DRAFT',
            'created_by' => $creador->id,
        ]);
    }
}
