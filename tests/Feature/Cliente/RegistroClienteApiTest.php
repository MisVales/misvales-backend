<?php

namespace Tests\Feature\Cliente;

use App\Http\Middleware\RequireActiveUser;
use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Cliente\ProtectorDatosCliente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistroClienteApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireActiveUser::class, RequireMfaCompleted::class]);
    }

    public function test_distribuidora_registra_cliente_en_una_transaccion(): void
    {
        [$usuario, $distribuidora] = $this->distribuidoraActiva();
        $usuariosAntes = User::query()->count();
        Sanctum::actingAs($usuario);

        $respuesta = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente());

        $respuesta->assertCreated()
            ->assertJsonPath('data.client_number', fn (string $numero): bool => str_starts_with($numero, 'CLI-'))
            ->assertJsonPath('data.full_name', 'María López García')
            ->assertJsonPath('data.distributor.id', $distribuidora->id)
            ->assertJsonPath('data.branch.id', $distribuidora->branch_id)
            ->assertJsonPath('data.bank_account.clabe_masked', '**************0018')
            ->assertJsonMissingPath('data.curp')
            ->assertJsonMissingPath('data.clabe');

        self::assertDatabaseCount('clients', 1);
        self::assertDatabaseCount('client_addresses', 1);
        self::assertDatabaseCount('client_bank_accounts', 1);
        self::assertDatabaseCount('client_distributor_assignments', 1);
        self::assertSame($usuariosAntes, User::query()->count());
        self::assertDatabaseHas('client_distributor_assignments', [
            'distributor_id' => $distribuidora->id,
            'branch_id' => $distribuidora->branch_id,
            'ends_at' => null,
        ]);
        self::assertDatabaseHas('audit_logs', ['event_name' => 'CLIENT_CREATED', 'result' => 'SUCCESS']);
        self::assertDatabaseHas('outbox_events', ['event_type' => 'CLIENT_CREATED']);
    }

    public function test_datos_personales_y_bancarios_quedan_cifrados(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente())
            ->assertCreated();

        $cliente = Cliente::query()->firstOrFail();
        $cuenta = $cliente->cuentaBancariaVigente()->firstOrFail();
        $protector = app(ProtectorDatosCliente::class);

        self::assertNotSame('LOGM900101MCLPRR01', $cliente->curp_ciphertext);
        self::assertSame('LOGM900101MCLPRR01', $protector->descifrar($cliente->curp_ciphertext));
        self::assertNotSame('000000000000000018', $cuenta->clabe_ciphertext);
        self::assertSame('000000000000000018', $protector->descifrar($cuenta->clabe_ciphertext));
        self::assertSame(64, strlen($cliente->curp_hmac));
        self::assertSame(64, strlen($cuenta->clabe_hmac));
    }

    public function test_rechaza_curp_duplicada_sin_revelar_cliente_existente(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/clients', $this->datosCliente())->assertCreated();
        $duplicado = $this->datosCliente([
            'address.exterior_number' => '999',
            'bank_account.clabe' => '000000000000000026',
        ]);

        $respuesta = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/clients', $duplicado);

        $respuesta->assertConflict()
            ->assertJsonPath('error.code', 'CLIENT_CURP_EXISTS')
            ->assertJsonMissingPath('error.details.client_id')
            ->assertJsonMissingPath('error.details.distributor_id');
        self::assertDatabaseCount('clients', 1);
        self::assertDatabaseHas('audit_logs', ['event_name' => 'CLIENT_DUPLICATE_CURP_REJECTED', 'result' => 'REJECTED']);
    }

    public function test_rechaza_curp_invalida_como_error_de_validacion(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente(['curp' => 'CURP INVALIDA']))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'CLIENT_VALIDATION_FAILED')
            ->assertJsonPath('error.fields.curp.0', 'La CURP no tiene un formato válido.');

        self::assertDatabaseCount('clients', 0);
    }

    public function test_rechaza_domicilio_duplicado_con_curp_distinta(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/clients', $this->datosCliente())->assertCreated();
        $duplicado = $this->datosCliente([
            'curp' => 'GOMC900101HCLNZR02',
            'rfc' => 'GOMC900101AB2',
            'bank_account.clabe' => '000000000000000026',
        ]);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $duplicado)
            ->assertConflict()
            ->assertJsonPath('error.code', 'CLIENT_ADDRESS_EXISTS');

        self::assertDatabaseCount('clients', 1);
        self::assertDatabaseHas('audit_logs', ['event_name' => 'CLIENT_DUPLICATE_ADDRESS_REJECTED']);
    }

    public function test_repeticion_idempotente_devuelve_el_mismo_cliente(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $clave = (string) Str::uuid();

        $primera = $this->withHeader('Idempotency-Key', $clave)->postJson('/api/v1/clients', $this->datosCliente())->assertCreated();
        $segunda = $this->withHeader('Idempotency-Key', $clave)->postJson('/api/v1/clients', $this->datosCliente())->assertCreated();

        self::assertSame($primera->json('data.id'), $segunda->json('data.id'));
        self::assertSame('true', $segunda->headers->get('X-Idempotent-Replayed'));
        self::assertDatabaseCount('clients', 1);
    }

    public function test_administrador_no_puede_registrar_clientes(): void
    {
        $administrador = User::factory()->create(['state' => 'ACTIVE']);
        $rol = Role::query()->where('code', 'admin')->firstOrFail();
        UserRoleScope::create([
            'user_id' => $administrador->id,
            'role_id' => $rol->id,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $administrador->id,
        ]);
        Sanctum::actingAs($administrador);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
        self::assertDatabaseCount('clients', 0);
    }

    public function test_distribuidora_lista_y_consulta_unicamente_sus_clientes(): void
    {
        [$usuarioA] = $this->distribuidoraActiva('DIS-2026-900001');
        Sanctum::actingAs($usuarioA);
        $clienteA = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente())
            ->assertCreated()->json('data.id');

        [$usuarioB] = $this->distribuidoraActiva('DIS-2026-900002');
        Sanctum::actingAs($usuarioB);
        $clienteB = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente([
                'curp' => 'GOMC900101HCLNZR02',
                'rfc' => 'GOMC900101AB2',
                'address.exterior_number' => '999',
                'bank_account.clabe' => '000000000000000026',
            ]))->assertCreated()->json('data.id');

        $this->getJson('/api/v1/clients')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $clienteB);
        $this->getJson("/api/v1/clients/{$clienteA}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'CLIENT_SCOPE_DENIED');
        self::assertDatabaseHas('audit_logs', ['event_name' => 'CLIENT_SCOPE_ACCESS_REJECTED', 'result' => 'REJECTED']);
        $this->getJson("/api/v1/clients/{$clienteB}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $clienteB)
            ->assertJsonMissingPath('data.curp')
            ->assertJsonMissingPath('data.rfc');
        $this->getJson('/api/v1/clients/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('error.code', 'CLIENT_NOT_FOUND');
    }

    public function test_gerente_general_puede_ver_datos_sensibles_y_el_acceso_se_audita(): void
    {
        [$distribuidora] = $this->distribuidoraActiva();
        Sanctum::actingAs($distribuidora);
        $clienteId = $this->registrarCliente();

        $gerente = User::factory()->create(['state' => 'ACTIVE']);
        $rol = Role::query()->where('code', 'general_manager')->firstOrFail();
        UserRoleScope::create([
            'user_id' => $gerente->id,
            'role_id' => $rol->id,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $gerente->id,
        ]);
        Sanctum::actingAs($gerente);

        $this->getJson("/api/v1/clients/{$clienteId}")
            ->assertSuccessful()
            ->assertJsonPath('data.curp', 'LOGM900101MCLPRR01')
            ->assertJsonPath('data.rfc', 'LOGM900101AB1');
        self::assertDatabaseHas('audit_logs', [
            'event_name' => 'CLIENT_SENSITIVE_DATA_VIEWED',
            'entity_id' => $clienteId,
            'result' => 'SUCCESS',
        ]);
    }

    public function test_administrador_consulta_globalmente_pero_sin_datos_sensibles(): void
    {
        [$distribuidora] = $this->distribuidoraActiva();
        Sanctum::actingAs($distribuidora);
        $clienteId = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente())
            ->assertCreated()->json('data.id');

        $administrador = User::factory()->create(['state' => 'ACTIVE']);
        $rol = Role::query()->where('code', 'admin')->firstOrFail();
        UserRoleScope::create([
            'user_id' => $administrador->id,
            'role_id' => $rol->id,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $administrador->id,
        ]);
        Sanctum::actingAs($administrador);

        $this->getJson('/api/v1/clients?search='.rawurlencode('María').'&per_page=10')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $clienteId);
        $this->getJson("/api/v1/clients/{$clienteId}")
            ->assertSuccessful()
            ->assertJsonPath('data.rfc_masked', 'LOG*******AB1')
            ->assertJsonMissingPath('data.curp')
            ->assertJsonMissingPath('data.rfc');
    }

    public function test_nueva_cuenta_bancaria_cierra_la_anterior_y_conserva_cifrado(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $clienteId = $this->registrarCliente();

        $respuesta = $this->postJson("/api/v1/clients/{$clienteId}/bank-accounts", [
            'bank_name' => 'Banco nuevo',
            'account_holder_name' => 'María López García',
            'account_number' => '1234567890',
            'clabe' => '000000000000000026',
            'change_reason' => 'Cambio de cuenta principal',
            'lock_version' => 1,
        ]);

        $respuesta->assertCreated()
            ->assertJsonPath('data.clabe_masked', '**************0026')
            ->assertJsonMissingPath('data.clabe');
        self::assertDatabaseCount('client_bank_accounts', 2);
        self::assertSame(1, DB::table('client_bank_accounts')->where('client_id', $clienteId)->where('is_current', true)->whereNull('ends_at')->count());
        self::assertSame(1, DB::table('client_bank_accounts')->where('client_id', $clienteId)->where('is_current', false)->whereNotNull('ends_at')->count());
        self::assertSame(2, Cliente::query()->findOrFail($clienteId)->lock_version);
        self::assertDatabaseHas('audit_logs', ['event_name' => 'CLIENT_BANK_ACCOUNT_ADDED']);
        $auditoria = AuditLog::query()->where('event_name', 'CLIENT_BANK_ACCOUNT_ADDED')->latest()->firstOrFail();
        self::assertSame('0018', $auditoria->previous_value['masked_ending']);
        self::assertSame('0026', $auditoria->new_value['masked_ending']);
        self::assertStringNotContainsString('000000000000000026', json_encode($auditoria->toArray(), JSON_THROW_ON_ERROR));

        $this->getJson("/api/v1/clients/{$clienteId}/bank-accounts")
            ->assertSuccessful()->assertJsonCount(2, 'data');

        $this->postJson("/api/v1/clients/{$clienteId}/bank-accounts", [
            'bank_name' => 'Banco obsoleto', 'account_holder_name' => 'María López García',
            'clabe' => '000000000000000034', 'change_reason' => 'Versión desactualizada', 'lock_version' => 1,
        ])->assertConflict()->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
    }

    public function test_cartera_calcula_saldo_informativo_y_rechaza_saldo_negativo(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $clienteId = $this->registrarCliente();

        $deuda = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
                'entry_type' => 'DEBT', 'amount' => '1000.0000',
                'occurred_at' => now()->toIso8601String(), 'due_date' => today()->addDays(10)->format('Y-m-d'),
            ])->assertCreated()
            ->assertJsonPath('data.amount', '1000.0000')
            ->assertJsonPath('summary.current_balance', '1000.0000')
            ->json('data.id');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
                'entry_type' => 'PARTIAL_PAYMENT', 'amount' => '250.1250',
                'occurred_at' => now()->addMinute()->toIso8601String(),
            ])->assertCreated()
            ->assertJsonPath('summary.current_balance', '749.8750')
            ->assertJsonPath('summary.informational_status', 'PARTIALLY_PAID');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
                'entry_type' => 'PAYMENT', 'amount' => '800.0000',
                'occurred_at' => now()->addMinutes(2)->toIso8601String(),
            ])->assertConflict()
            ->assertJsonPath('error.code', 'CLIENT_PORTFOLIO_BALANCE_NEGATIVE');

        self::assertDatabaseCount('client_portfolio_entries', 2);
        $this->getJson("/api/v1/clients/{$clienteId}/portfolio-entries")
            ->assertSuccessful()
            ->assertJsonPath('summary.current_balance', '749.8750')
            ->assertJsonPath('summary.entries_count', 2);
        self::assertDatabaseCount('credit_line_movements', 0);
        self::assertSame(0, DB::table('outbox_events')->whereIn('event_type', [
            'PAYMENT_RECONCILED', 'CREDIT_LINE_RECOVERED', 'RELATION_PAYMENT_REGISTERED',
        ])->count());
        self::assertNotNull($deuda);
    }

    public function test_cartera_rechaza_importe_cero_y_ajuste_sin_motivo(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $clienteId = $this->registrarCliente();

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
                'entry_type' => 'DEBT', 'amount' => '0.0000', 'occurred_at' => now()->toIso8601String(),
            ])->assertUnprocessable()->assertJsonPath('error.code', 'CLIENT_PORTFOLIO_ENTRY_INVALID');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
                'entry_type' => 'ADJUSTMENT_INCREASE', 'amount' => '10.0000', 'occurred_at' => now()->toIso8601String(),
            ])->assertUnprocessable()->assertJsonPath('error.code', 'CLIENT_PORTFOLIO_ENTRY_INVALID');
        self::assertDatabaseCount('client_portfolio_entries', 0);
    }

    public function test_actualizacion_de_cartera_respeta_inmutabilidad_y_version(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $clienteId = $this->registrarCliente();
        $movimientoId = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
                'entry_type' => 'NOTE', 'occurred_at' => now()->toIso8601String(), 'note' => 'Nota inicial',
            ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/clients/{$clienteId}/portfolio-entries/{$movimientoId}", [
            'amount' => '10.0000', 'lock_version' => 1,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'CLIENT_PORTFOLIO_ENTRY_IMMUTABLE');

        $this->patchJson("/api/v1/clients/{$clienteId}/portfolio-entries/{$movimientoId}", [
            'note' => 'Nota corregida', 'lock_version' => 1,
        ])->assertSuccessful()
            ->assertJsonPath('data.note', 'Nota corregida')
            ->assertJsonPath('data.lock_version', 2);

        $this->patchJson("/api/v1/clients/{$clienteId}/portfolio-entries/{$movimientoId}", [
            'note' => 'Actualización obsoleta', 'lock_version' => 1,
        ])->assertConflict()->assertJsonPath('error.code', 'RESOURCE_VERSION_CONFLICT');
        self::assertDatabaseHas('audit_logs', ['event_name' => 'CLIENT_PORTFOLIO_ENTRY_UPDATED']);
        $auditoria = AuditLog::query()->where('event_name', 'CLIENT_PORTFOLIO_ENTRY_UPDATED')->latest()->firstOrFail();
        self::assertSame('Nota inicial', $auditoria->previous_value['note']);
    }

    public function test_alcance_de_gerente_de_sucursal_coordinador_y_verificador(): void
    {
        [$usuarioA, $distribuidoraA] = $this->distribuidoraActiva('DIS-2026-900001');
        Sanctum::actingAs($usuarioA);
        $clienteA = $this->registrarCliente();
        [$usuarioB] = $this->distribuidoraActiva('DIS-2026-900002');
        Sanctum::actingAs($usuarioB);
        $this->registrarCliente([
            'curp' => 'GOMC900101HCLNZR02', 'rfc' => 'GOMC900101AB2',
            'address.exterior_number' => '999', 'bank_account.clabe' => '000000000000000026',
        ]);

        $gerente = $this->usuarioDeRol('branch_manager', 'BRANCH', $distribuidoraA->branch_id);
        Sanctum::actingAs($gerente);
        $this->getJson('/api/v1/clients')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $clienteA);

        $coordinador = $this->usuarioDeRol('coordinator', 'BRANCH', $distribuidoraA->branch_id);
        CoordinatorDistributorAssignment::create([
            'coordinator_id' => $coordinador->id,
            'distributor_id' => $distribuidoraA->id,
            'branch_id' => $distribuidoraA->branch_id,
            'valid_from' => now(), 'status' => 'ACTIVE',
            'assigned_by' => $coordinador->id, 'assignment_reason' => 'Prueba de alcance',
        ]);
        Sanctum::actingAs($coordinador);
        $this->getJson('/api/v1/clients')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $clienteA);

        $verificador = $this->usuarioDeRol('verifier', 'BRANCH', $distribuidoraA->branch_id);
        Sanctum::actingAs($verificador);
        $this->getJson('/api/v1/clients')->assertForbidden()->assertJsonPath('error.code', 'AUTH_SCOPE_DENIED');
    }

    public function test_coordinador_no_consulta_por_detalle_una_distribuidora_no_asignada_de_su_sucursal(): void
    {
        [$usuarioA, $distribuidoraA] = $this->distribuidoraActiva('DIS-2026-900011');
        Sanctum::actingAs($usuarioA);
        $clienteA = $this->registrarCliente();

        [$usuarioB, $distribuidoraB] = $this->distribuidoraActiva('DIS-2026-900012');
        $distribuidoraB->forceFill(['branch_id' => $distribuidoraA->branch_id])->save();
        Sanctum::actingAs($usuarioB);
        $clienteB = $this->registrarCliente([
            'curp' => 'GOMC900101HCLNZR02', 'rfc' => 'GOMC900101AB2',
            'address.exterior_number' => '999', 'bank_account.clabe' => '000000000000000026',
        ]);

        $coordinador = $this->usuarioDeRol('coordinator', 'BRANCH', $distribuidoraA->branch_id);
        CoordinatorDistributorAssignment::create([
            'coordinator_id' => $coordinador->id,
            'distributor_id' => $distribuidoraA->id,
            'branch_id' => $distribuidoraA->branch_id,
            'valid_from' => now(), 'status' => 'ACTIVE',
            'assigned_by' => $coordinador->id, 'assignment_reason' => 'Prueba de detalle',
        ]);

        Sanctum::actingAs($coordinador);
        $this->getJson("/api/v1/clients/{$clienteA}")->assertSuccessful();
        $this->getJson("/api/v1/clients/{$clienteB}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'CLIENT_SCOPE_DENIED');
        $this->getJson("/api/v1/clients/{$clienteB}/bank-accounts")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'CLIENT_SCOPE_DENIED');
        $this->getJson("/api/v1/clients/{$clienteB}/portfolio-entries")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'CLIENT_SCOPE_DENIED');

        self::assertSame(3, AuditLog::query()
            ->where('entity_id', $clienteB)
            ->where('event_name', 'CLIENT_SCOPE_ACCESS_REJECTED')
            ->where('result', 'REJECTED')
            ->count());
    }

    public function test_filtros_de_saldo_y_estado_de_cartera_se_aplican_en_sql(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $conSaldo = $this->registrarCliente();
        $sinCartera = $this->registrarCliente([
            'curp' => 'GOMC900101HCLNZR02', 'rfc' => 'GOMC900101AB2',
            'address.exterior_number' => '999', 'bank_account.clabe' => '000000000000000026',
        ]);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/clients/{$conSaldo}/portfolio-entries", [
            'entry_type' => 'DEBT', 'amount' => '500.0000', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/clients/{$conSaldo}/portfolio-entries", [
            'entry_type' => 'PARTIAL_PAYMENT', 'amount' => '100.0000', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();

        $this->getJson('/api/v1/clients?has_portfolio_balance=1')
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $conSaldo);
        $this->getJson('/api/v1/clients?portfolio_status=PARTIALLY_PAID')
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $conSaldo);
        $this->getJson('/api/v1/clients?has_portfolio_balance=0')
            ->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sinCartera);
    }

    public function test_clientes_no_se_eliminan_fisicamente(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $cliente = Cliente::query()->findOrFail($this->registrarCliente());

        $this->expectException(QueryException::class);
        $cliente->delete();
    }

    public function test_todas_las_entidades_del_modulo_impiden_eliminacion_fisica(): void
    {
        [$usuario] = $this->distribuidoraActiva();
        Sanctum::actingAs($usuario);
        $clienteId = $this->registrarCliente();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/clients/{$clienteId}/portfolio-entries", [
            'entry_type' => 'NOTE', 'occurred_at' => now()->toIso8601String(), 'note' => 'Registro protegido',
        ])->assertCreated();

        foreach (['client_addresses', 'client_bank_accounts', 'client_distributor_assignments', 'client_portfolio_entries'] as $indice => $tabla) {
            $id = DB::table($tabla)->where('client_id', $clienteId)->value('id');
            DB::statement("SAVEPOINT client_delete_{$indice}");

            try {
                DB::table($tabla)->where('id', $id)->delete();
                self::fail("La tabla {$tabla} permitió eliminación física.");
            } catch (QueryException) {
                DB::statement("ROLLBACK TO SAVEPOINT client_delete_{$indice}");
            }

            self::assertDatabaseHas($tabla, ['id' => $id]);
        }
    }

    private function distribuidoraActiva(string $numero = 'DIS-2026-900001'): array
    {
        $usuario = User::factory()->create(['state' => 'ACTIVE']);
        $solicitud = DistributorApplication::factory()->create();
        $distribuidora = Distribuidora::create([
            'application_id' => $solicitud->id,
            'user_id' => $usuario->id,
            'distributor_number' => $numero,
            'branch_id' => $solicitud->branch_id,
        ]);
        $distribuidora->forceFill([
            'status' => 'ACTIVE',
            'activated_at' => now(),
            'activated_by' => $usuario->id,
            'lock_version' => 1,
        ])->save();
        $rol = Role::query()->where('code', 'distributor')->firstOrFail();
        UserRoleScope::create([
            'user_id' => $usuario->id,
            'role_id' => $rol->id,
            'branch_id' => $solicitud->branch_id,
            'scope_type' => 'DISTRIBUTOR',
            'scope_id' => $distribuidora->id,
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $usuario->id,
        ]);

        return [$usuario, $distribuidora];
    }

    private function datosCliente(array $cambios = []): array
    {
        $datos = [
            'first_name' => 'María',
            'first_last_name' => 'López',
            'second_last_name' => 'García',
            'curp' => 'LOGM900101MCLPRR01',
            'rfc' => 'LOGM900101AB1',
            'birth_date' => '1990-01-01',
            'birth_place' => 'Torreón',
            'birth_state' => 'Coahuila',
            'birth_city' => 'Torreón',
            'official_id_type' => 'INE',
            'official_id_number' => '0000000000000',
            'address' => [
                'street' => 'Avenida Central',
                'exterior_number' => '120',
                'interior_number' => null,
                'neighborhood' => 'Centro',
                'postal_code' => '27000',
                'municipality' => 'Torreón',
                'city' => 'Torreón',
                'state' => 'Coahuila',
                'country' => 'MX',
            ],
            'bank_account' => [
                'bank_name' => 'Banco de prueba',
                'account_holder_name' => 'María López García',
                'account_number' => null,
                'clabe' => '000000000000000018',
            ],
        ];

        foreach ($cambios as $ruta => $valor) {
            data_set($datos, $ruta, $valor);
        }

        return $datos;
    }

    private function registrarCliente(array $cambios = []): string
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/clients', $this->datosCliente($cambios))
            ->assertCreated()
            ->json('data.id');
    }

    private function usuarioDeRol(string $codigoRol, string $tipoAlcance, ?string $sucursalId = null): User
    {
        $usuario = User::factory()->create(['state' => 'ACTIVE']);
        UserRoleScope::create([
            'user_id' => $usuario->id,
            'role_id' => Role::query()->where('code', $codigoRol)->firstOrFail()->id,
            'branch_id' => $sucursalId,
            'scope_type' => $tipoAlcance,
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $usuario->id,
        ]);

        return $usuario;
    }
}
