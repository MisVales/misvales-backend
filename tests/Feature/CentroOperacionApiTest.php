<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\OutboxEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Services\Notificaciones\ProyectorNotificaciones;
use App\Services\Reportes\ServicioReportes;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CentroOperacionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_outbox_resuelve_destinatarios_reales_y_no_duplica_notificaciones(): void
    {
        Mail::fake();
        $branch = Branch::factory()->create();
        $general = $this->user('general_manager');
        $manager = $this->user('branch_manager', $branch->id);
        $coordinator = $this->user('coordinator', $branch->id);
        $distributorUser = $this->user('distributor', $branch->id);
        $distributor = Distribuidora::factory()->active()->create(['user_id' => $distributorUser->id, 'branch_id' => $branch->id]);
        CoordinatorDistributorAssignment::query()->create(['coordinator_id' => $coordinator->id, 'distributor_id' => $distributor->id, 'branch_id' => $branch->id, 'valid_from' => now()->subDay(), 'status' => 'ACTIVE', 'assigned_by' => $manager->id]);
        OutboxEvent::query()->create(['event_type' => 'CreditIncreaseRequested', 'payload' => ['distributor_id' => $distributor->id, 'branch_id' => $branch->id], 'status' => 'PENDING']);

        self::assertSame(4, app(ProyectorNotificaciones::class)->proyectar());
        self::assertSame(0, app(ProyectorNotificaciones::class)->proyectar());
        $this->assertDatabaseCount('notification_deliveries', 4);
        $this->assertDatabaseCount('notifications', 4);
        $this->assertDatabaseMissing('notification_deliveries', ['status' => 'FAILED']);
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'SENT', 'result' => 'DELIVERED', 'attempts' => 1]);
        foreach ([$general, $manager, $coordinator, $distributorUser] as $recipient) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id]);
        }
    }

    public function test_proyector_normaliza_payload_legacy_codificado_como_string(): void
    {
        $general = $this->user('general_manager');
        OutboxEvent::query()->create([
            'event_type' => 'CreditIncreaseRequested',
            'payload' => json_encode(['user_id' => $general->id], JSON_THROW_ON_ERROR),
            'status' => 'PENDING',
        ]);

        self::assertSame(1, app(ProyectorNotificaciones::class)->proyectar());
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $general->id]);
    }

    public function test_bandeja_contador_deep_link_y_lectura_son_solo_del_destinatario(): void
    {
        $user = $this->user('distributor', Branch::factory()->create()->id);
        $other = $this->user('distributor', Branch::factory()->create()->id);
        $notification = $user->notifications()->create(['id' => fake()->uuid(), 'type' => 'Domain', 'data' => ['title' => 'Vale', 'description' => 'Actualización', 'event_type' => 'VoucherGenerated', 'entity_type' => 'voucher', 'entity_id' => fake()->uuid(), 'deep_link' => '/vales']]);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.data.0.data.deep_link', '/vales');
        $this->patchJson("/api/v1/notifications/{$notification->id}/read")->assertOk()->assertJsonPath('data.read_at', fn ($value) => $value !== null);
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/notifications/{$notification->id}/read")->assertNotFound();
    }

    public function test_los_veinte_reportes_vigentes_son_paginados_y_ejecutan_consultas_reales(): void
    {
        $this->withoutExceptionHandling();
        Sanctum::actingAs($this->user('general_manager'));
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(20, 'data');
        foreach (ServicioReportes::REPORTS as $report) {
            $this->getJson("/api/v1/reports/{$report}?per_page=10")->assertOk()->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']]);
        }
    }

    public function test_auditoria_es_inmutable_y_logs_tienen_correlacion_sin_payload(): void
    {
        $this->withoutExceptionHandling();
        $general = $this->user('general_manager');
        $audit = AuditLog::query()->create(['actor_id' => $general->id, 'entity_type' => 'voucher', 'event_name' => 'TEST', 'entity_id' => fake()->uuid(), 'new_value' => ['token' => 'secret-value', 'status' => 'OK'], 'result' => 'SUCCESS']);
        AuditLog::query()->create(['actor_id' => $general->id, 'actor_role' => 'general_manager', 'entity_type' => 'branches', 'event_name' => 'BRANCH_CREATED', 'entity_id' => fake()->uuid(), 'result' => 'SUCCESS']);
        AuditLog::query()->create(['actor_id' => $general->id, 'actor_role' => 'general_manager', 'entity_type' => 'vouchers', 'event_name' => 'VOUCHER_GENERATED', 'entity_id' => fake()->uuid(), 'result' => 'SUCCESS']);
        try {
            DB::transaction(fn () => $audit->update(['result' => 'CHANGED']));
            self::fail('La auditoría no debe permitir actualización.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        Sanctum::actingAs($general);
        $this->withHeaders(['X-Request-Id' => 'req-test', 'X-Correlation-Id' => 'corr-test', 'X-Trace-Id' => 'trace-test'])->getJson('/api/v1/audit-logs')->assertOk()->assertHeader('X-Correlation-Id', 'corr-test')->assertJsonFragment(['token' => '[REDACTED]'])->assertJsonMissingPath('data.data.0.actor.branch_id');
        $this->getJson('/api/v1/audit-logs/options')->assertOk()
            ->assertJsonFragment(['event_name' => 'BRANCH_CREATED', 'entity_type' => 'branches'])
            ->assertJsonFragment(['event_name' => 'VOUCHER_GENERATED', 'entity_type' => 'vouchers']);
        $this->getJson('/api/v1/audit-logs?event_names[]=BRANCH_CREATED&event_names[]=VOUCHER_GENERATED')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
        $this->getJson('/api/v1/operational-logs?correlation_id=corr-test')->assertOk()->assertJsonPath('data.data.0.path', '/api/v1/audit-logs')->assertJsonMissingPath('data.data.0.context.password');
    }

    public function test_gerente_de_sucursal_solo_consulta_auditoria_de_su_sucursal(): void
    {
        $propia = Branch::factory()->create();
        $ajena = Branch::factory()->create();
        $gerente = $this->user('branch_manager', $propia->id);

        $visible = AuditLog::query()->create([
            'actor_id' => $gerente->id,
            'branch_id' => $propia->id,
            'entity_type' => 'branches',
            'event_name' => 'OWN_BRANCH_EVENT',
            'entity_id' => fake()->uuid(),
            'result' => 'SUCCESS',
        ]);
        AuditLog::query()->create([
            'actor_id' => $gerente->id,
            'branch_id' => $ajena->id,
            'entity_type' => 'branches',
            'event_name' => 'OTHER_BRANCH_EVENT',
            'entity_id' => fake()->uuid(),
            'result' => 'SUCCESS',
        ]);

        Sanctum::actingAs($gerente);

        $this->getJson('/api/v1/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $visible->id)
            ->assertJsonMissing(['event_name' => 'OTHER_BRANCH_EVENT']);

        $this->getJson('/api/v1/audit-logs?branch_id='.$ajena->id)
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    private function user(string $roleCode, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        UserRoleScope::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branchId, 'assigned_by_user_id' => $user->id]);

        return $user;
    }
}
