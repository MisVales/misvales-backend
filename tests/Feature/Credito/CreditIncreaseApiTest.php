<?php

namespace Tests\Feature\Credito;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Branch;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\LineaCredito;
use App\Models\OutboxEvent;
use App\Models\RestriccionUsoCredito;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CreditIncreaseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);

        // Evitar efectos secundarios de auditoría y resolver tolerancia fija
        $this->mock(\App\Services\Credito\AuditorIncrementos::class, function ($mock) {
            $mock->shouldReceive('registrar')->andReturn();
        });

        $this->mock(\App\Services\ConfiguracionServicio::class, function ($mock) {
            $mock->shouldReceive('resolver')->with('CREDIT_TOLERANCE_AMOUNT')->andReturn([
                'value' => '500.0000',
                'version_id' => 'v1.0.0'
            ]);
        });
    }

    public function test_distributor_can_create_request_and_idempotency()
    {
        $branch = Branch::factory()->create();

        $distributor = $this->usuarioConRol('distributor', $branch->id);

        \App\Models\Distribuidora::factory()->create([
            'id' => $distributor->id,
            'user_id' => $distributor->id,
            'branch_id' => $branch->id,
            'distributor_number' => sprintf('DIS-%d-%06d', now()->year, rand(1, 999999)),
            'status' => 'ACTIVE',
        ]);

        $linea = LineaCredito::factory()->create([
            'distributor_id' => $distributor->id,
            'total_authorized' => '30000.0000',
            'used_balance' => '12000.0000',
            'lock_version' => 1,
        ]);

        // Crear asignación de coordinador válida
        $coordinator = $this->usuarioConRol('coordinator', $branch->id);
        CoordinatorDistributorAssignment::create([
            'coordinator_id' => $coordinator->id,
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'valid_from' => now(),
            'status' => 'ACTIVE',
            'assigned_by' => $coordinator->id,
        ]);

        Sanctum::actingAs($distributor);

        $key = (string) \Illuminate\Support\Str::uuid();

        $response = $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/distributors/{$distributor->id}/credit-increase-requests", [
                'requested_amount' => '10000.0000',
                'request_reason' => 'Aumento de demanda',
                'lock_version' => 1,
            ]);

        $response->assertSuccessful()->assertJsonPath('data.requested_amount', '10000.0000')
            ->assertJsonPath('data.status', 'REQUESTED');

        // Reintento con la misma idempotencia no duplica
        $this->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/distributors/{$distributor->id}/credit-increase-requests", [
                'requested_amount' => '10000.0000',
                'request_reason' => 'Aumento de demanda',
                'lock_version' => 1,
            ])->assertSuccessful();

        $this->assertDatabaseCount('credit_increase_requests', 1);
    }

    public function test_coordinator_can_preauthorize_and_reject()
    {
        $branch = Branch::factory()->create();
        $distributor = $this->usuarioConRol('distributor', $branch->id);
        \App\Models\Distribuidora::factory()->create([
            'id' => $distributor->id,
            'user_id' => $distributor->id,
            'branch_id' => $branch->id,
            'distributor_number' => sprintf('DIS-%d-%06d', now()->year, rand(1, 999999)),
            'status' => 'ACTIVE',
        ]);

        $linea = LineaCredito::factory()->create(['distributor_id' => $distributor->id]);

        $coordinator = $this->usuarioConRol('coordinator', $branch->id);
        CoordinatorDistributorAssignment::create([
            'coordinator_id' => $coordinator->id,
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'valid_from' => now(),
            'status' => 'ACTIVE',
            'assigned_by' => $coordinator->id,
        ]);

        Sanctum::actingAs($distributor);

        $resp = $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson("/api/v1/distributors/{$distributor->id}/credit-increase-requests", [
                'requested_amount' => '5000.0000',
                'request_reason' => 'Necesidad temporal',
                'lock_version' => 1,
            ]);

        $resp->assertSuccessful();
        $requestId = $resp->json('data.id');

        Sanctum::actingAs($coordinator);

        // Preautorizar
        $pre = $this->postJson("/api/v1/credit-increase-requests/{$requestId}/preauthorize", [
            'recommended_amount' => '4000.0000',
            'reason' => 'Importe recomendado',
            'lock_version' => 1,
        ]);

        $pre->assertSuccessful()->assertJsonPath('data.status', 'PREAUTHORIZED')
            ->assertJsonPath('data.recommended_amount', '4000.0000');

        // Rechazar operativamente sobre una nueva solicitud
        Sanctum::actingAs($distributor);
        $resp2 = $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson("/api/v1/distributors/{$distributor->id}/credit-increase-requests", [
                'requested_amount' => '2000.0000',
                'request_reason' => 'Otro motivo',
                'lock_version' => 1,
            ]);
        $id2 = $resp2->json('data.id');

        Sanctum::actingAs($coordinator);
        $rej = $this->postJson("/api/v1/credit-increase-requests/{$id2}/reject-by-coordinator", [
            'reason' => 'Comportamiento de pago insuficiente',
            'lock_version' => 1,
        ]);

        $rej->assertSuccessful()->assertJsonPath('data.status', 'REJECTED_BY_COORDINATOR');
    }

    public function test_manager_can_authorize_and_creates_movement_and_restriction()
    {
        $branch = Branch::factory()->create();
        $distributor = $this->usuarioConRol('distributor', $branch->id);

        \App\Models\Distribuidora::factory()->create([
            'id' => $distributor->id,
            'user_id' => $distributor->id,
            'branch_id' => $branch->id,
            'distributor_number' => sprintf('DIS-%d-%06d', now()->year, rand(1, 999999)),
            'status' => 'ACTIVE',
        ]);

        $linea = LineaCredito::factory()->create([
            'distributor_id' => $distributor->id,
            'total_authorized' => '10000.0000',
            'used_balance' => '2000.0000',
            'lock_version' => 1,
        ]);

        $coordinator = $this->usuarioConRol('coordinator', $branch->id);
        CoordinatorDistributorAssignment::create([
            'coordinator_id' => $coordinator->id,
            'distributor_id' => $distributor->id,
            'branch_id' => $branch->id,
            'valid_from' => now(),
            'status' => 'ACTIVE',
            'assigned_by' => $coordinator->id,
        ]);

        Sanctum::actingAs($distributor);
        $resp = $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson("/api/v1/distributors/{$distributor->id}/credit-increase-requests", [
                'requested_amount' => '5000.0000',
                'request_reason' => 'Demanda',
                'lock_version' => 1,
            ]);

        $requestId = $resp->json('data.id');

        Sanctum::actingAs($coordinator);
        $pre = $this->postJson("/api/v1/credit-increase-requests/{$requestId}/preauthorize", [
            'recommended_amount' => '5000.0000',
            'reason' => 'Ok',
            'lock_version' => 1,
        ])->assertSuccessful();

        $manager = $this->usuarioConRol('branch_manager', $branch->id);
        Sanctum::actingAs($manager);

        $dec = $this->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson("/api/v1/credit-increase-requests/{$requestId}/manager-decision", [
                'decision' => 'APPROVE_REQUESTED',
                'reason' => 'Historial favorable',
                'lock_version' => 2,
            ])->assertSuccessful();

        $dec->assertJsonPath('data.status', 'AUTHORIZED_TOTAL')
            ->assertJsonPath('data.authorized_amount', '5000.0000');

        $linea->refresh();
        $this->assertEquals('15000.0000', $linea->total_authorized);

        $this->assertDatabaseHas('credit_line_movements', [
            'credit_line_id' => $linea->id,
            'type' => 'INCREASE',
            'amount' => '5000.0000',
        ]);

        $this->assertDatabaseHas('credit_usage_restrictions', [
            'credit_line_id' => $linea->id,
            'type' => 'POST_INCREASE_50_PERCENT',
            'status' => 'ACTIVE',
        ]);
    }

    private function usuarioConRol(string $rol, ?string $sucursalId = null): User
    {
        $email = \Illuminate\Support\Str::uuid().'@example.test';
        $usuario = User::factory()->create([
            'email' => $email,
            'normalized_email' => $email,
            'state' => 'ACTIVE',
        ]);

        $role = \App\Models\Role::query()->where('code', $rol)->firstOrFail();

        \App\Models\UserRoleScope::query()->create([
            'user_id' => $usuario->id,
            'role_id' => $role->id,
            'branch_id' => $sucursalId,
            'assigned_by_user_id' => $usuario->id,
        ]);

        return $usuario;
    }
}
