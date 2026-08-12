<?php

namespace Tests\Feature\Cliente;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\AsignacionClienteDistribuidora;
use App\Models\Branch;
use App\Models\Cliente;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\MovimientoCarteraCliente;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TransferenciaOrganizacionalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_transferencia_exige_saldo_cero_y_completa_el_historial_solo_tras_todas_las_decisiones(): void
    {
        $branch = Branch::factory()->create();
        $originUser = $this->user('distributor', $branch->id);
        $receiverUser = $this->user('distributor', $branch->id);
        $coordinator = $this->user('coordinator', $branch->id);
        $origin = Distribuidora::factory()->active()->create(['user_id' => $originUser->id, 'branch_id' => $branch->id]);
        $receiver = Distribuidora::factory()->active()->create(['user_id' => $receiverUser->id, 'branch_id' => $branch->id]);
        CoordinatorDistributorAssignment::query()->create(['coordinator_id' => $coordinator->id, 'distributor_id' => $origin->id, 'branch_id' => $branch->id, 'valid_from' => now()->subDay(), 'status' => 'ACTIVE', 'assigned_by' => $coordinator->id]);
        $client = Cliente::factory()->create(['created_by' => $originUser->id]);
        $assignment = AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $origin->id, 'branch_id' => $branch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $originUser->id]);
        MovimientoCarteraCliente::factory()->create(['client_id' => $client->id, 'distributor_id' => $origin->id, 'entry_type' => 'DEBT', 'amount' => '100.0000', 'recorded_by' => $originUser->id]);

        Sanctum::actingAs($originUser);
        $this->postIdempotent("/api/v1/clients/{$client->id}/transfers", ['destination_distributor_id' => $receiver->id])->assertStatus(409)->assertJsonPath('error.code', 'CLIENT_TRANSFER_BALANCE_NOT_ZERO');
        MovimientoCarteraCliente::factory()->create(['client_id' => $client->id, 'distributor_id' => $origin->id, 'entry_type' => 'PAYMENT', 'amount' => '100.0000', 'recorded_by' => $originUser->id]);
        $transfer = $this->postIdempotent("/api/v1/clients/{$client->id}/transfers", ['destination_distributor_id' => $receiver->id])->assertCreated()->json('data');

        Sanctum::actingAs($receiverUser);
        $this->postIdempotent("/api/v1/client-transfers/{$transfer['id']}/preaccept", ['accept' => true])->assertOk()->assertJsonPath('data.status', 'PREACCEPTED');
        Sanctum::actingAs($coordinator);
        $this->postIdempotent("/api/v1/client-transfers/{$transfer['id']}/origin-decision", ['authorize' => true, 'reason' => 'Salida verificada'])->assertOk()->assertJsonPath('data.status', 'ORIGIN_AUTHORIZED');
        Sanctum::actingAs($receiverUser);
        $this->postIdempotent("/api/v1/client-transfers/{$transfer['id']}/complete", [])->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseMissing('client_distributor_assignments', ['id' => $assignment->id, 'ends_at' => null]);
        $this->assertDatabaseHas('client_distributor_assignments', ['client_id' => $client->id, 'distributor_id' => $receiver->id, 'ends_at' => null]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CLIENT_TRANSFER_COMPLETED']);
    }

    public function test_rechazos_no_cambian_asignacion_y_transiciones_no_se_saltan(): void
    {
        [$branch, $originUser, $receiverUser, $coordinator, $origin, $receiver, $client] = $this->contextoTransferencia();
        Sanctum::actingAs($originUser);
        $transfer = $this->postIdempotent("/api/v1/clients/{$client->id}/transfers", ['destination_distributor_id' => $receiver->id])->assertCreated()->json('data');
        Sanctum::actingAs($receiverUser);
        $this->postIdempotent("/api/v1/client-transfers/{$transfer['id']}/complete", [])->assertStatus(409)->assertJsonPath('error.code', 'CLIENT_TRANSFER_INVALID_STATE');
        $this->postIdempotent("/api/v1/client-transfers/{$transfer['id']}/preaccept", ['accept' => false])->assertOk()->assertJsonPath('data.status', 'REJECTED_BY_RECEIVER');
        $this->assertDatabaseHas('client_distributor_assignments', ['client_id' => $client->id, 'distributor_id' => $origin->id, 'ends_at' => null]);
    }

    public function test_cambios_gerenciales_preservan_historial_y_no_dejan_distribuidora_activa_sin_coordinador(): void
    {
        $originBranch = Branch::factory()->create();
        $destinationBranch = Branch::factory()->create();
        $manager = $this->user('general_manager');
        $oldCoordinator = $this->user('coordinator', $originBranch->id);
        $newCoordinator = $this->user('coordinator', $originBranch->id);
        $destinationCoordinator = $this->user('coordinator', $destinationBranch->id);
        $distributorUser = $this->user('distributor', $originBranch->id);
        $distributor = Distribuidora::factory()->active()->create(['user_id' => $distributorUser->id, 'branch_id' => $originBranch->id]);
        CoordinatorDistributorAssignment::query()->create(['coordinator_id' => $oldCoordinator->id, 'distributor_id' => $distributor->id, 'branch_id' => $originBranch->id, 'valid_from' => now()->subDay(), 'status' => 'ACTIVE', 'assigned_by' => $manager->id]);
        $client = Cliente::factory()->create(['created_by' => $distributorUser->id]);
        AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $distributor->id, 'branch_id' => $originBranch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $distributorUser->id]);

        Sanctum::actingAs($manager);
        $this->postIdempotent("/api/v1/distributors/{$distributor->id}/branch-change", ['destination_branch_id' => $destinationBranch->id, 'destination_coordinator_id' => $destinationCoordinator->id, 'reason' => 'Reestructura'])->assertStatus(409)->assertJsonPath('error.code', 'DISTRIBUTOR_HAS_ASSIGNED_CLIENTS');
        $this->postIdempotent("/api/v1/distributors/{$distributor->id}/coordinator-change", ['destination_coordinator_id' => $newCoordinator->id, 'reason' => 'Cambio operativo'])->assertCreated();
        $this->assertDatabaseHas('coordinator_distributor_assignments', ['distributor_id' => $distributor->id, 'coordinator_id' => $oldCoordinator->id, 'status' => 'REASSIGNED']);
        $this->assertDatabaseHas('coordinator_distributor_assignments', ['distributor_id' => $distributor->id, 'coordinator_id' => $newCoordinator->id, 'status' => 'ACTIVE', 'valid_to' => null]);
        $this->assertDatabaseHas('organizational_change_events', ['subject_id' => $distributor->id, 'type' => 'COORDINATOR_CHANGE']);

        $holdingUser = $this->user('distributor', $originBranch->id);
        $holding = Distribuidora::factory()->active()->create(['user_id' => $holdingUser->id, 'branch_id' => $originBranch->id]);
        $this->postIdempotent("/api/v1/clients/{$client->id}/administrative-reassignment", ['destination_distributor_id' => $holding->id, 'reason' => 'Reasignación previa'])->assertCreated();
        $this->postIdempotent("/api/v1/distributors/{$distributor->id}/branch-change", ['destination_branch_id' => $destinationBranch->id, 'destination_coordinator_id' => $destinationCoordinator->id, 'reason' => 'Reestructura'])->assertOk()->assertJsonPath('data.branch_id', $destinationBranch->id);
        $this->assertDatabaseHas('organizational_change_events', ['subject_id' => $client->id, 'type' => 'CLIENT_ADMIN_REASSIGNMENT']);
        $this->assertDatabaseHas('organizational_change_events', ['subject_id' => $distributor->id, 'type' => 'DISTRIBUTOR_BRANCH_CHANGE']);
        $this->assertDatabaseHas('coordinator_distributor_assignments', ['distributor_id' => $distributor->id, 'coordinator_id' => $destinationCoordinator->id, 'branch_id' => $destinationBranch->id, 'status' => 'ACTIVE', 'valid_to' => null]);
    }

    public function test_salida_de_coordinador_exige_destino_para_todas_sus_distribuidoras_en_una_operacion(): void
    {
        $branch = Branch::factory()->create();
        $manager = $this->user('branch_manager', $branch->id);
        $origin = $this->user('coordinator', $branch->id);
        $destination = $this->user('coordinator', $branch->id);
        $distributors = collect(range(1, 2))->map(function () use ($branch, $origin, $manager): Distribuidora {
            $user = $this->user('distributor', $branch->id);
            $distributor = Distribuidora::factory()->active()->create(['user_id' => $user->id, 'branch_id' => $branch->id]);
            CoordinatorDistributorAssignment::query()->create(['coordinator_id' => $origin->id, 'distributor_id' => $distributor->id, 'branch_id' => $branch->id, 'valid_from' => now()->subDay(), 'status' => 'ACTIVE', 'assigned_by' => $manager->id]);

            return $distributor;
        });
        Sanctum::actingAs($manager);
        $this->postIdempotent("/api/v1/coordinators/{$origin->id}/exit-reassignment", ['reason' => 'Salida', 'assignments' => [['distributor_id' => $distributors[0]->id, 'destination_coordinator_id' => $destination->id]]])->assertUnprocessable()->assertJsonPath('error.code', 'COORDINATOR_DESTINATIONS_INCOMPLETE');
        $assignments = $distributors->map(fn (Distribuidora $distributor): array => ['distributor_id' => $distributor->id, 'destination_coordinator_id' => $destination->id])->all();
        $this->postIdempotent("/api/v1/coordinators/{$origin->id}/exit-reassignment", ['reason' => 'Salida', 'assignments' => $assignments])->assertCreated()->assertJsonCount(2, 'data');
        $this->assertDatabaseMissing('coordinator_distributor_assignments', ['coordinator_id' => $origin->id, 'status' => 'ACTIVE', 'valid_to' => null]);
    }

    public function test_gerente_de_sucursal_no_reasigna_fuera_de_su_alcance(): void
    {
        $originBranch = Branch::factory()->create();
        $destinationBranch = Branch::factory()->create();
        $manager = $this->user('branch_manager', $originBranch->id);
        $originUser = $this->user('distributor', $originBranch->id);
        $destinationUser = $this->user('distributor', $destinationBranch->id);
        $origin = Distribuidora::factory()->active()->create(['user_id' => $originUser->id, 'branch_id' => $originBranch->id]);
        $destination = Distribuidora::factory()->active()->create(['user_id' => $destinationUser->id, 'branch_id' => $destinationBranch->id]);
        $client = Cliente::factory()->create(['created_by' => $originUser->id]);
        AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $origin->id, 'branch_id' => $originBranch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $originUser->id]);
        Sanctum::actingAs($manager);
        $this->postIdempotent("/api/v1/clients/{$client->id}/administrative-reassignment", ['destination_distributor_id' => $destination->id, 'reason' => 'Fuera de alcance'])->assertForbidden()->assertJsonPath('error.code', 'ORGANIZATION_CHANGE_FORBIDDEN');
        $this->assertDatabaseHas('client_distributor_assignments', ['client_id' => $client->id, 'distributor_id' => $origin->id, 'ends_at' => null]);
    }

    private function contextoTransferencia(): array
    {
        $branch = Branch::factory()->create();
        $originUser = $this->user('distributor', $branch->id);
        $receiverUser = $this->user('distributor', $branch->id);
        $coordinator = $this->user('coordinator', $branch->id);
        $origin = Distribuidora::factory()->active()->create(['user_id' => $originUser->id, 'branch_id' => $branch->id]);
        $receiver = Distribuidora::factory()->active()->create(['user_id' => $receiverUser->id, 'branch_id' => $branch->id]);
        CoordinatorDistributorAssignment::query()->create(['coordinator_id' => $coordinator->id, 'distributor_id' => $origin->id, 'branch_id' => $branch->id, 'valid_from' => now()->subDay(), 'status' => 'ACTIVE', 'assigned_by' => $coordinator->id]);
        $client = Cliente::factory()->create(['created_by' => $originUser->id]);
        AsignacionClienteDistribuidora::factory()->create(['client_id' => $client->id, 'distributor_id' => $origin->id, 'branch_id' => $branch->id, 'starts_at' => now()->subDay(), 'ends_at' => null, 'assigned_by' => $originUser->id]);

        return [$branch, $originUser, $receiverUser, $coordinator, $origin, $receiver, $client];
    }

    private function postIdempotent(string $uri, array $data)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($uri, $data);
    }

    private function user(string $roleCode, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        UserRoleScope::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'branch_id' => $branchId, 'assigned_by_user_id' => $user->id]);

        return $user;
    }
}
