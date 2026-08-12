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
        foreach ([$general, $manager, $coordinator, $distributorUser] as $recipient) {
            $this->assertDatabaseHas('notifications', ['notifiable_id' => $recipient->id]);
        }
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

    public function test_los_veintidos_reportes_son_paginados_y_ejecutan_consultas_reales(): void
    {
        $this->withoutExceptionHandling();
        Sanctum::actingAs($this->user('general_manager'));
        $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(22, 'data');
        foreach (ServicioReportes::REPORTS as $report) {
            $this->getJson("/api/v1/reports/{$report}?per_page=10")->assertOk()->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']]);
        }
    }

    public function test_auditoria_es_inmutable_y_logs_tienen_correlacion_sin_payload(): void
    {
        $this->withoutExceptionHandling();
        $general = $this->user('general_manager');
        $audit = AuditLog::query()->create(['actor_id' => $general->id, 'entity_type' => 'voucher', 'event_name' => 'TEST', 'entity_id' => fake()->uuid(), 'new_value' => ['token' => 'secret-value', 'status' => 'OK'], 'result' => 'SUCCESS']);
        try {
            DB::transaction(fn () => $audit->update(['result' => 'CHANGED']));
            self::fail('La auditoría no debe permitir actualización.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
        Sanctum::actingAs($general);
        $this->withHeaders(['X-Request-Id' => 'req-test', 'X-Correlation-Id' => 'corr-test', 'X-Trace-Id' => 'trace-test'])->getJson('/api/v1/audit-logs')->assertOk()->assertHeader('X-Correlation-Id', 'corr-test')->assertJsonPath('data.data.0.new_value.token', '[REDACTED]');
        $this->getJson('/api/v1/operational-logs?correlation_id=corr-test')->assertOk()->assertJsonPath('data.data.0.path', '/api/v1/audit-logs')->assertJsonMissingPath('data.data.0.context.password');
    }

    private function user(string $roleCode, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        UserRoleScope::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branchId, 'assigned_by_user_id' => $user->id]);

        return $user;
    }
}
