<?php

namespace Tests\Feature\Distribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Mail\ActivationInvitationMail;
use App\Models\AccountInvitation;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\AsignacionCategoriaDistribuidora;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\DatosPersonalesSolicitud;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\LineaCredito;
use App\Models\MovimientoLineaCredito;
use App\Models\RestriccionUsoCredito;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Models\VerificationVisit;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivacionDistribuidoraApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('configuracion:CREDIT_TOLERANCE_AMOUNT');
        $this->seed(RolesAndPermissionsSeeder::class);
        $autorConfiguracion = User::factory()->create(['state' => 'ACTIVE']);
        $definicion = ConfigurationDefinition::query()->create([
            'key' => 'CREDIT_TOLERANCE_AMOUNT',
            'name' => 'Tolerancia de crédito',
            'value_type' => 'DECIMAL',
            'status' => 'ACTIVE',
            'created_by' => $autorConfiguracion->id,
        ]);
        ConfigurationVersion::query()->create([
            'configuration_definition_id' => $definicion->id,
            'version' => 1,
            'value' => '500.0000',
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(),
            'reason' => 'Prueba de activación',
            'created_by' => $autorConfiguracion->id,
            'published_by' => $autorConfiguracion->id,
            'published_at' => now(),
        ]);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireActiveUser::class, RequireMfaCompleted::class]);
        Mail::fake();
    }

    public function test_materializa_solicitud_aprobada_en_una_sola_operacion(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        Sanctum::actingAs($gerente);

        $respuesta = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ]);

        $respuesta->assertSuccessful()
            ->assertJsonPath('data.application_id', $solicitud->id)
            ->assertJsonPath('data.status', 'PENDING_ACTIVATION')
            ->assertJsonPath('data.initial_credit.total_authorized', '15000.0000')
            ->assertJsonPath('data.initial_restriction.type', 'INITIAL_50_PERCENT');

        self::assertDatabaseCount('distributors', 1);
        self::assertDatabaseCount('coordinator_distributor_assignments', 1);
        self::assertDatabaseCount('distributor_category_assignments', 1);
        self::assertDatabaseCount('credit_lines', 1);
        self::assertDatabaseCount('credit_line_movements', 1);
        self::assertDatabaseCount('credit_usage_restrictions', 1);
        self::assertDatabaseCount('account_invitations', 1);
        self::assertDatabaseHas('distributor_applications', ['id' => $solicitud->id, 'status' => 'ACTIVE']);

        $usuario = User::query()->where('normalized_email', 'aspirante@example.test')->firstOrFail();
        self::assertNull($usuario->password);
        self::assertSame('PENDING_ACTIVATION', $usuario->state);
        self::assertDatabaseHas('user_role_scopes', ['user_id' => $usuario->id, 'branch_id' => $solicitud->branch_id]);
        self::assertDatabaseHas('user_role_scopes', [
            'user_id' => $usuario->id,
            'scope_type' => 'DISTRIBUTOR',
            'scope_id' => Distribuidora::query()->value('id'),
        ]);
        self::assertSame(
            'distributor',
            $usuario->roleScopes()->with('role')->firstOrFail()->role->code,
        );
        self::assertDatabaseHas('credit_line_movements', [
            'type' => 'INITIAL_AUTHORIZATION',
            'amount' => '15000.0000',
            'total_authorized_before' => '15000.0000',
            'total_authorized_after' => '15000.0000',
            'used_balance_before' => '0.0000',
            'used_balance_after' => '0.0000',
            'source_type' => 'DISTRIBUTOR_APPLICATION_AUTHORIZATION',
            'source_id' => ApplicationAuthorization::query()->where('application_id', $solicitud->id)->value('id'),
        ]);
        self::assertDatabaseHas('credit_usage_restrictions', [
            'type' => 'INITIAL_50_PERCENT',
            'status' => 'ACTIVE',
            'base_total' => '15000.0000',
            'consumed_at' => null,
        ]);
        self::assertSame(64, strlen((string) AccountInvitation::query()->value('token_hash')));
        Mail::assertQueued(ActivationInvitationMail::class, 1);
    }

    public function test_reintento_no_duplica_materializacion_ni_correo(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        Sanctum::actingAs($gerente);

        foreach ([(string) Str::uuid(), (string) Str::uuid()] as $clave) {
            $this->withHeader('Idempotency-Key', $clave)
                ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                    'category_version_id' => $version->id,
                ])->assertSuccessful();
        }

        self::assertDatabaseCount('distributors', 1);
        self::assertDatabaseCount('credit_lines', 1);
        self::assertDatabaseCount('credit_line_movements', 1);
        self::assertDatabaseCount('account_invitations', 1);
        Mail::assertQueued(ActivationInvitationMail::class, 1);
    }

    public function test_no_materializa_solicitud_sin_autorizacion_favorable(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        DB::table('distributor_applications')
            ->where('id', $solicitud->id)
            ->update(['status' => ApplicationStatus::REJECTED->value]);
        Sanctum::actingAs($gerente);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertConflict()
            ->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_APPROVED');

        self::assertDatabaseCount('distributors', 0);
        self::assertDatabaseHas('outbox_events', ['event_type' => 'DISTRIBUTOR_ACTIVATION_FAILED']);
    }

    public function test_cambio_de_categoria_conserva_historial_y_controla_version(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $categoriaNueva = Category::create([
            'code' => 'PLATA', 'status' => 'ACTIVE', 'created_by' => $gerente->id,
        ]);
        $versionNueva = CategoryVersion::create([
            'category_id' => $categoriaNueva->id,
            'version' => 1,
            'name' => 'Plata',
            'profit_percentage' => '0.040000',
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(),
            'reason' => 'Publicación inicial',
            'created_by' => $gerente->id,
            'published_by' => $gerente->id,
            'published_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($gerente);

        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [
            'category_version_id' => $versionNueva->id,
            'starts_at' => now()->addMinute()->toIso8601String(),
            'reason' => 'Cambio comercial',
            'lock_version' => 1,
        ])->assertSuccessful()->assertJsonPath('data.category_version_id', $versionNueva->id);

        self::assertDatabaseCount('distributor_category_assignments', 2);
        self::assertDatabaseHas('outbox_events', ['event_type' => 'DISTRIBUTOR_CATEGORY_ASSIGNED']);
        self::assertSame(1, DB::table('outbox_events')
            ->where('event_type', 'DISTRIBUTOR_CATEGORY_ASSIGNED')
            ->whereRaw("payload->>'event_code' = ?", ['EV-093'])
            ->count());
        $this->getJson("/api/v1/distributors/{$distribuidora->id}/category-assignments")
            ->assertSuccessful()->assertJsonCount(2, 'data');

        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [
            'category_version_id' => $version->id,
            'starts_at' => now()->addMinutes(2)->toIso8601String(),
            'lock_version' => 1,
        ])->assertConflict()->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
    }

    public function test_reenvio_revoca_la_invitacion_anterior_y_no_expone_token(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $anterior = AccountInvitation::query()->firstOrFail();
        Sanctum::actingAs($gerente);

        $respuesta = $this->postJson("/api/v1/distributors/{$distribuidora->id}/activation-invitations/resend");

        $respuesta->assertSuccessful()->assertJsonMissingPath('token');
        self::assertSame('REVOKED', $anterior->refresh()->state);
        self::assertDatabaseCount('account_invitations', 2);
        self::assertSame(1, AccountInvitation::query()->where('state', 'ACTIVE')->count());
        self::assertDatabaseHas('outbox_events', ['event_type' => 'DISTRIBUTOR_ACTIVATION_INVITATION_RESENT']);
        Mail::assertQueued(ActivationInvitationMail::class, 2);
    }

    public function test_gerente_de_sucursal_no_puede_operar_otra_sucursal(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $otraSucursal = Branch::create([
            'code' => 'SLP-01', 'name' => 'San Luis', 'is_headquarters' => false,
            'status' => 'ACTIVE', 'created_by' => $gerente->id,
        ]);
        $gerenteAjeno = $this->usuarioConRol('branch_manager', $otraSucursal->id, $gerente);
        Sanctum::actingAs($gerenteAjeno);

        $this->getJson("/api/v1/distributors/{$distribuidora->id}")->assertForbidden();
        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [
            'category_version_id' => $version->id,
            'starts_at' => now()->addMinute()->toIso8601String(),
            'lock_version' => 1,
        ])->assertForbidden();
        $this->postJson("/api/v1/distributors/{$distribuidora->id}/activation-invitations/resend")
            ->assertForbidden();
    }

    public function test_gerente_de_sucursal_no_activa_solicitud_de_otra_sucursal(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $otraSucursal = Branch::create([
            'code' => 'GDL-01', 'name' => 'Guadalajara', 'is_headquarters' => false,
            'status' => 'ACTIVE', 'created_by' => $gerente->id,
        ]);
        $gerenteAjeno = $this->usuarioConRol('branch_manager', $otraSucursal->id, $gerente);
        Sanctum::actingAs($gerenteAjeno);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertForbidden()->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
        self::assertDatabaseCount('distributors', 0);
    }

    public function test_completar_invitacion_activa_usuario_y_distribuidora(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $tokenIntercambio = Str::random(60);
        $invitacion = AccountInvitation::query()->firstOrFail();
        $invitacion->forceFill([
            'state' => 'PREPARED',
            'exchange_token_hash' => hash('sha256', $tokenIntercambio),
            'exchange_expires_at' => now()->addMinutes(15),
            'mfa_setup_completed_at' => now(),
        ])->save();

        $this->postJson('/api/v1/auth/invitations/complete', [
            'exchange_token' => $tokenIntercambio,
            'codes_safeguarded' => true,
        ])->assertSuccessful();

        self::assertSame('ACTIVE', $distribuidora->usuario->refresh()->state);
        self::assertSame('ACTIVE', $distribuidora->refresh()->status->value);
        self::assertNotNull($distribuidora->activated_at);
        self::assertSame('CONSUMED', $invitacion->refresh()->state);
        self::assertDatabaseHas('outbox_events', ['event_type' => 'DISTRIBUTOR_ACCESS_ACTIVATED']);
    }

    public function test_exige_visita_y_evaluacion_favorables_sin_dejar_datos_parciales(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        DB::table('application_evaluations')->where('application_id', $solicitud->id)->delete();
        Sanctum::actingAs($gerente);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_APPROVED');

        self::assertDatabaseCount('distributors', 0);
        self::assertDatabaseMissing('users', ['normalized_email' => 'aspirante@example.test']);
        self::assertDatabaseCount('credit_lines', 0);
        self::assertDatabaseCount('account_invitations', 0);
    }

    public function test_un_fallo_intermedio_revierte_toda_la_activacion(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        Role::query()->where('code', 'distributor')->update(['code' => 'distributor_missing']);
        Sanctum::actingAs($gerente);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_ACTIVATION_STATE_INVALID');

        self::assertDatabaseCount('distributors', 0);
        self::assertDatabaseMissing('users', ['normalized_email' => 'aspirante@example.test']);
        self::assertDatabaseCount('coordinator_distributor_assignments', 0);
        self::assertDatabaseCount('credit_lines', 0);
        self::assertDatabaseCount('account_invitations', 0);
    }

    public function test_categoria_programada_no_se_muestra_antes_de_su_vigencia(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $categoria = Category::create(['code' => 'FUTURA', 'status' => 'ACTIVE', 'created_by' => $gerente->id]);
        $futura = CategoryVersion::create([
            'category_id' => $categoria->id, 'version' => 1, 'name' => 'Futura',
            'profit_percentage' => '0.070000', 'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(), 'reason' => 'Programación',
            'created_by' => $gerente->id, 'published_by' => $gerente->id, 'published_at' => now()->subDay(),
        ]);
        Sanctum::actingAs($gerente);

        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [
            'category_version_id' => $futura->id,
            'starts_at' => now()->addDay()->toIso8601String(),
            'lock_version' => 1,
        ])->assertSuccessful();

        $this->getJson("/api/v1/distributors/{$distribuidora->id}")
            ->assertSuccessful()->assertJsonPath('data.category.version_id', $version->id);
    }

    public function test_rechaza_categoria_en_borrador_desde_api(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $borrador = CategoryVersion::create([
            'category_id' => $version->category_id, 'version' => 2, 'name' => 'Borrador',
            'profit_percentage' => '0.080000', 'status' => 'DRAFT',
            'effective_from' => now()->subDay(), 'reason' => 'Borrador', 'created_by' => $gerente->id,
        ]);
        Sanctum::actingAs($gerente);

        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [
            'category_version_id' => $borrador->id,
            'starts_at' => now()->addMinute()->toIso8601String(),
            'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'DISTRIBUTOR_CATEGORY_NOT_PUBLISHED');
    }

    public function test_clave_idempotente_no_puede_reutilizarse_con_otro_payload(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        Sanctum::actingAs($gerente);
        $clave = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $clave)
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertSuccessful();

        $this->withHeader('Idempotency-Key', $clave)
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => (string) Str::uuid(),
            ])->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED_WITH_DIFFERENT_PAYLOAD');
    }

    public function test_matriz_de_lectura_y_modificacion_por_roles(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $coordinador = User::query()->findOrFail($solicitud->coordinator_id);
        Sanctum::actingAs($coordinador);
        $this->getJson("/api/v1/distributors/{$distribuidora->id}")->assertSuccessful();
        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [])->assertForbidden();

        $admin = $this->usuarioConRol('admin', null, $gerente);
        Sanctum::actingAs($admin);
        $this->getJson("/api/v1/distributors/{$distribuidora->id}")->assertSuccessful();
        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [])->assertForbidden();

        $usuarioDistribuidora = $distribuidora->usuario;
        $usuarioDistribuidora->update(['state' => 'ACTIVE']);
        Sanctum::actingAs($usuarioDistribuidora);
        $this->getJson("/api/v1/distributors/{$distribuidora->id}")->assertSuccessful();
        $this->postJson("/api/v1/distributors/{$distribuidora->id}/category-assignments", [])->assertForbidden();
        $otra = Distribuidora::factory()->create();
        $this->getJson("/api/v1/distributors/{$otra->id}")->assertForbidden();
    }

    public function test_listado_aplica_busqueda_filtros_y_alcance(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        Sanctum::actingAs($gerente);

        $consulta = http_build_query([
            'search' => 'Ana',
            'branch_id' => $solicitud->branch_id,
            'coordinator_id' => $solicitud->coordinator_id,
            'category_id' => $version->category_id,
            'status' => 'PENDING_ACTIVATION',
            'activation_status' => 'PENDING_ACTIVATION',
            'per_page' => 10,
            'sort' => 'distributor_number',
            'direction' => 'asc',
        ]);
        $this->getJson("/api/v1/distributors?{$consulta}")
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $distribuidora->id)
            ->assertJsonPath('data.0.category.id', $version->category_id);
    }

    public function test_auditoria_contiene_contexto_y_no_datos_sensibles(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $this->withHeader('X-Trace-Id', 'trace-module-06');
        $this->activar($gerente, $solicitud, $version);

        foreach ([
            'DISTRIBUTOR_CREATED',
            'DISTRIBUTOR_NUMBER_ASSIGNED',
            'DISTRIBUTOR_COORDINATOR_ASSIGNED',
            'DISTRIBUTOR_CATEGORY_ASSIGNED',
            'INITIAL_CREDIT_LINE_CREATED',
            'INITIAL_CREDIT_RESTRICTION_CREATED',
            'DISTRIBUTOR_USER_CREATED',
            'DISTRIBUTOR_ACTIVATION_INVITATION_SENT',
            'EV-008', 'EV-011', 'EV-012',
        ] as $evento) {
            self::assertDatabaseHas('outbox_events', ['event_type' => $evento]);
            self::assertDatabaseHas('audit_logs', ['event_name' => $evento, 'result' => 'SUCCESS']);
        }

        $auditoria = AuditLog::query()->where('event_name', 'DISTRIBUTOR_USER_CREATED')->firstOrFail();
        self::assertSame($gerente->id, $auditoria->actor_id);
        self::assertSame($solicitud->branch_id, $auditoria->branch_id);
        self::assertSame('trace-module-06', $auditoria->trace_id);
        $contenido = json_encode([$auditoria->previous_value, $auditoria->new_value]);
        self::assertStringNotContainsString('aspirante@example.test', $contenido);
        self::assertStringNotContainsString('token', mb_strtolower($contenido));
        self::assertStringNotContainsString('password', mb_strtolower($contenido));
    }

    public function test_solicitud_con_evaluacion_desfavorable_nunca_se_materializa(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        DB::table('application_evaluations')->where('application_id', $solicitud->id)
            ->update(['result' => ApplicationEvaluationResult::DOES_NOT_COMPLY->value]);
        Sanctum::actingAs($gerente);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_APPLICATION_NOT_APPROVED');
        self::assertDatabaseCount('distributors', 0);
    }

    public function test_sucursal_o_coordinador_inactivo_bloquean_activacion(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        DB::table('users')->where('id', $solicitud->coordinator_id)->update(['state' => 'DISABLED']);
        Sanctum::actingAs($gerente);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID');
        self::assertDatabaseCount('distributors', 0);
    }

    public function test_genera_numeros_unicos_para_solicitudes_distintas(): void
    {
        [$gerenteA, $solicitudA, $versionA] = $this->escenarioAutorizado('A');
        [$gerenteB, $solicitudB, $versionB] = $this->escenarioAutorizado('B');
        $primera = $this->activar($gerenteA, $solicitudA, $versionA);
        $segunda = $this->activar($gerenteB, $solicitudB, $versionB);

        self::assertNotSame($primera->distributor_number, $segunda->distributor_number);
        self::assertMatchesRegularExpression('/^DIS-\d{4}-\d{6,}$/', $primera->distributor_number);
        self::assertMatchesRegularExpression('/^DIS-\d{4}-\d{6,}$/', $segunda->distributor_number);
    }

    public function test_limita_reenvios_por_distribuidora_y_devuelve_error_de_dominio(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        Sanctum::actingAs($gerente);

        for ($intento = 0; $intento < 3; $intento++) {
            $this->postJson("/api/v1/distributors/{$distribuidora->id}/activation-invitations/resend")
                ->assertSuccessful();
        }
        $this->postJson("/api/v1/distributors/{$distribuidora->id}/activation-invitations/resend")
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'DISTRIBUTOR_INVITATION_RATE_LIMITED');
        self::assertDatabaseHas('audit_logs', [
            'entity_id' => $distribuidora->id,
            'result' => 'FAILED',
            'reason' => 'DISTRIBUTOR_INVITATION_RATE_LIMITED',
        ]);
    }

    public function test_factories_del_modulo_crean_registros_validos(): void
    {
        $distribuidora = Distribuidora::factory()->create();
        self::assertDatabaseHas('distributors', ['id' => $distribuidora->id]);

        $asignacion = AsignacionCategoriaDistribuidora::factory()->create();
        self::assertDatabaseHas('distributor_category_assignments', ['id' => $asignacion->id]);

        $linea = LineaCredito::factory()->create();
        $movimiento = MovimientoLineaCredito::factory()->create();
        $restriccion = RestriccionUsoCredito::factory()->create();
        self::assertDatabaseHas('credit_lines', ['id' => $linea->id]);
        self::assertDatabaseHas('credit_line_movements', ['id' => $movimiento->id]);
        self::assertDatabaseHas('credit_usage_restrictions', ['id' => $restriccion->id]);
    }

    public function test_conflicto_de_correo_no_revela_la_cuenta_existente(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        User::factory()->create([
            'email' => 'aspirante@example.test',
            'normalized_email' => 'aspirante@example.test',
            'state' => 'ACTIVE',
        ]);
        Sanctum::actingAs($gerente);

        $respuesta = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ]);

        $respuesta->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_USER_CONFLICT');
        self::assertStringNotContainsString('aspirante@example.test', $respuesta->getContent());
        self::assertDatabaseCount('distributors', 0);
    }

    public function test_reenvio_en_estado_activo_falla_y_se_audita(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        DB::table('distributors')->where('id', $distribuidora->id)->update([
            'status' => 'ACTIVE',
            'activated_at' => now(),
            'activated_by' => $gerente->id,
        ]);
        Sanctum::actingAs($gerente);

        $this->postJson("/api/v1/distributors/{$distribuidora->id}/activation-invitations/resend")
            ->assertConflict()->assertJsonPath('error.code', 'DISTRIBUTOR_ACTIVATION_STATE_INVALID');
        self::assertDatabaseHas('audit_logs', [
            'entity_id' => $distribuidora->id,
            'result' => 'FAILED',
            'reason' => 'DISTRIBUTOR_ACTIVATION_STATE_INVALID',
        ]);
    }

    public function test_idempotency_key_es_obligatoria_y_tiene_formato_de_error_consistente(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        Sanctum::actingAs($gerente);

        $this->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
            'category_version_id' => $version->id,
        ])->assertStatus(400)->assertJsonPath('error.code', 'MISSING_IDEMPOTENCY_KEY');
    }

    public function test_restricciones_de_base_impiden_duplicar_una_materializacion_competidora(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $otroUsuario = User::factory()->create();

        try {
            DB::transaction(function () use ($distribuidora, $otroUsuario): void {
                DB::table('distributors')->insert([
                    'id' => (string) Str::uuid(),
                    'application_id' => $distribuidora->application_id,
                    'user_id' => $otroUsuario->id,
                    'distributor_number' => 'DIS-2099-999999',
                    'branch_id' => $distribuidora->branch_id,
                    'status' => 'PENDING_ACTIVATION',
                    'lock_version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            self::fail('La restricción única debió impedir la materialización competidora.');
        } catch (QueryException) {
            self::assertSame(1, Distribuidora::query()->where('application_id', $solicitud->id)->count());
        }
    }

    public function test_distribuidoras_e_historial_de_categorias_no_se_eliminan_fisicamente(): void
    {
        [$gerente, $solicitud, $version] = $this->escenarioAutorizado();
        $distribuidora = $this->activar($gerente, $solicitud, $version);
        $asignacion = $distribuidora->asignacionesCategoria()->firstOrFail();

        foreach ([
            ['table' => 'distributor_category_assignments', 'id' => $asignacion->id],
            ['table' => 'distributors', 'id' => $distribuidora->id],
        ] as $objetivo) {
            try {
                DB::transaction(fn () => DB::table($objetivo['table'])->where('id', $objetivo['id'])->delete());
                self::fail('El trigger debió impedir la eliminación física.');
            } catch (QueryException) {
                self::assertDatabaseHas($objetivo['table'], ['id' => $objetivo['id']]);
            }
        }
    }

    private function activar(User $gerente, DistributorApplication $solicitud, CategoryVersion $version): Distribuidora
    {
        Sanctum::actingAs($gerente);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/distributor-applications/{$solicitud->id}/activation", [
                'category_version_id' => $version->id,
            ])->assertSuccessful();

        return Distribuidora::query()->where('application_id', $solicitud->id)->firstOrFail();
    }

    private function escenarioAutorizado(string $sufijo = ''): array
    {
        $creador = User::factory()->create(['state' => 'ACTIVE']);
        $sucursal = Branch::create([
            'code' => 'MTY-01'.$sufijo, 'name' => 'Monterrey', 'is_headquarters' => false,
            'status' => 'ACTIVE', 'created_by' => $creador->id,
        ]);
        $gerente = $this->usuarioConRol('general_manager', null, $creador);
        $coordinador = $this->usuarioConRol('coordinator', $sucursal->id, $creador);
        $solicitud = DistributorApplication::create([
            'application_number' => 'SOL-2026-'.random_int(100000, 999999),
            'status' => ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION,
            'branch_id' => $sucursal->id,
            'coordinator_id' => $coordinador->id,
            'section_declarations' => [],
            'created_by' => $creador->id,
        ]);
        DatosPersonalesSolicitud::query()->forceCreate([
            'application_id' => $solicitud->id,
            'first_name' => 'Ana',
            'first_last_name' => 'López',
            'curp_ciphertext' => 'ciphertext',
            'curp_hmac' => hash('sha256', 'curp'.$sufijo),
            'birth_date' => '1990-01-01',
            'birth_place' => 'Monterrey',
            'birth_state' => 'Nuevo León',
            'birth_city' => 'Monterrey',
            'email' => "aspirante{$sufijo}@example.test",
            'phone_number' => '8112345678',
            'official_id_type' => 'INE',
            'official_id_number_ciphertext' => 'ciphertext',
            'official_id_number_hmac' => hash('sha256', 'id'.$sufijo),
        ]);
        $verificador = $this->usuarioConRol('verifier', $sucursal->id, $creador);
        $visita = new VerificationVisit([
            'application_id' => $solicitud->id,
            'verifier_id' => $verificador->id,
            'assigned_by' => $coordinador->id,
            'assigned_at' => now()->subDays(3),
            'completed_at' => now()->subDays(2),
            'visited_at' => now()->subDays(2),
        ]);
        $visita->forceFill([
            'status' => VerificationVisitStatus::COMPLETED,
            'result' => VerificationVisitResult::FAVORABLE,
        ])->save();
        $evaluacion = new ApplicationEvaluation([
            'application_id' => $solicitud->id,
            'verification_visit_id' => $visita->id,
            'reason' => 'Cumple los criterios',
            'evaluated_by' => $coordinador->id,
            'evaluated_at' => now()->subDay(),
        ]);
        $evaluacion->forceFill(['result' => ApplicationEvaluationResult::COMPLIES])->save();
        $autorizacion = new ApplicationAuthorization([
            'application_id' => $solicitud->id,
            'initial_credit_line_amount' => '15000.0000',
            'reason' => 'Aprobada',
            'authorized_by' => $gerente->id,
            'authorized_at' => now(),
        ]);
        $autorizacion->forceFill(['decision' => ApplicationAuthorizationDecision::APPROVED])->save();

        $categoria = Category::create([
            'code' => 'ORO'.$sufijo, 'status' => 'ACTIVE', 'created_by' => $gerente->id,
        ]);
        $version = CategoryVersion::create([
            'category_id' => $categoria->id,
            'version' => 1,
            'name' => 'Oro',
            'profit_percentage' => '0.060000',
            'status' => 'PUBLISHED',
            'effective_from' => now()->subDay(),
            'reason' => 'Publicación inicial',
            'created_by' => $gerente->id,
            'published_by' => $gerente->id,
            'published_at' => now()->subDay(),
        ]);

        return [$gerente, $solicitud, $version];
    }

    private function usuarioConRol(string $codigo, ?string $sucursalId, User $asignador): User
    {
        $usuario = User::factory()->create(['state' => 'ACTIVE']);
        UserRoleScope::create([
            'user_id' => $usuario->id,
            'role_id' => Role::query()->where('code', $codigo)->firstOrFail()->id,
            'branch_id' => $sucursalId,
            'assigned_by_user_id' => $asignador->id,
            'assigned_at' => now(),
            'scope_type' => $sucursalId === null ? 'GLOBAL' : 'BRANCH',
            'status' => 'ACTIVE',
        ]);

        return $usuario;
    }
}
