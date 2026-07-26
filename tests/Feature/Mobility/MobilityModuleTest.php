<?php

declare(strict_types=1);

namespace Tests\Feature\Mobility;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\Mobility\Application\Contracts\ClientAssignmentSnapshot;
use App\Modules\Mobility\Application\Contracts\ClientMobilityPort;
use App\Modules\Mobility\Application\Contracts\DistributorSnapshot;
use App\Modules\Mobility\Application\Services\MobilityWorkflowService;
use App\Modules\Mobility\Domain\Enums\AdministrativeReassignmentStatus;
use App\Modules\Mobility\Domain\Enums\ClientTransferStatus;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeClientMobilityPort;
use Tests\TestCase;

final class MobilityModuleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $coordinator;

    private User $origin;

    private User $recipient;

    private string $clientId;

    private FakeClientMobilityPort $clients;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
        $this->branch = Branch::query()->firstOrFail();
        $this->coordinator = $this->user(RoleCode::COORDINATOR);
        $this->origin = $this->user(RoleCode::DISTRIBUTOR, ['coordinator_id' => $this->coordinator->id]);
        $this->recipient = $this->user(RoleCode::DISTRIBUTOR, ['coordinator_id' => $this->coordinator->id]);
        $this->clientId = (string) Str::uuid();
        DB::table('clients')->insert([
            'id' => $this->clientId,
            'given_names' => 'Cliente',
            'surnames' => 'Movilidad',
            'curp_ciphertext' => 'protected',
            'curp_hmac' => hash('sha256', $this->clientId),
            'curp_last4' => '0000',
            'created_by' => $this->origin->id,
            'registration_operation_id' => (string) Str::uuid(),
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clients = new FakeClientMobilityPort;
        $this->clients->addDistributor($this->distributor($this->origin));
        $this->clients->addDistributor($this->distributor($this->recipient));
        $assignment = $this->assignment();
        DB::table('client_distributor_assignments')->insert([
            'id' => $assignment->assignmentId,
            'client_id' => $this->clientId,
            'distributor_id' => $this->origin->public_id,
            'branch_id_snapshot' => $this->branch->id,
            'effective_from' => now(),
            'assignment_type' => 'INITIAL',
            'active_slot' => true,
            'created_at' => now(),
        ]);
        $this->clients->addAssignment($assignment);
        $this->app->instance(ClientMobilityPort::class, $this->clients);
    }

    public function test_complete_transfer_revalidates_every_actor_and_changes_one_assignment(): void
    {
        $service = app(MobilityWorkflowService::class);
        $transfer = $service->createTransfer($this->origin, $this->input(), 'create-1', $this->correlation());
        $transfer = $service->preaccept($this->recipient, $transfer->id, true, 1, null, 'preaccept-1', $this->correlation());
        $transfer = $service->decideOrigin($this->coordinator, $transfer->id, true, 2, null, 'decision-1', $this->correlation());
        $transfer = $service->finalizeTransfer($this->recipient, $transfer->id, 3, 'final-1', $this->correlation());
        $replay = $service->finalizeTransfer($this->recipient, $transfer->id, 3, 'final-1', $this->correlation());

        self::assertSame(ClientTransferStatus::COMPLETED, $transfer->status);
        self::assertTrue($replay->getAttribute('_idempotency_replayed'));
        self::assertSame(1, $this->clients->applied);
        self::assertSame($this->recipient->public_id, $this->clients->lockAssignment($this->clientId)->distributorId);
        self::assertSame(4, DB::table('mobility_state_history')->where('aggregate_id', $transfer->id)->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'ClientTransferCompleted')->count());
        $this->assertDatabaseHas('client_transfer_requests', ['id' => $transfer->id, 'active_slot' => null]);
    }

    public function test_zero_balance_and_single_active_process_are_enforced(): void
    {
        $service = app(MobilityWorkflowService::class);
        $service->createTransfer($this->origin, $this->input(), 'active-1', $this->correlation());

        try {
            $service->createTransfer($this->origin, $this->input(), 'active-2', $this->correlation());
            self::fail('A second active transfer must be rejected.');
        } catch (MobilityException $exception) {
            self::assertSame('CLIENT_MOBILITY_ALREADY_ACTIVE', $exception->errorCode());
        }

        ClientTransfer::query()->update(['status' => ClientTransferStatus::CANCELLED->value, 'active_slot' => null]);
        $this->clients->addAssignment($this->assignment(total: '1.0000'));
        $this->expectExceptionObject(MobilityException::balanceNotZero());
        $service->createTransfer($this->origin, $this->input(), 'balance-1', $this->correlation());
    }

    public function test_only_the_recipient_can_preaccept_and_version_is_required(): void
    {
        $service = app(MobilityWorkflowService::class);
        $transfer = $service->createTransfer($this->origin, $this->input(), 'create-scope', $this->correlation());
        $other = $this->user(RoleCode::DISTRIBUTOR, ['coordinator_id' => $this->coordinator->id]);

        try {
            $service->preaccept($other, $transfer->id, true, 1, null, 'wrong-actor', $this->correlation());
            self::fail('A different distributor must not decide.');
        } catch (MobilityException $exception) {
            self::assertSame('MOBILITY_SCOPE_DENIED', $exception->errorCode());
        }

        $this->expectExceptionObject(MobilityException::versionConflict());
        $service->preaccept($this->recipient, $transfer->id, true, 99, null, 'stale', $this->correlation());
    }

    public function test_api_resolves_origin_from_session_and_replays_creation(): void
    {
        Sanctum::actingAs($this->origin);
        $headers = ['Idempotency-Key' => 'api-create-1', 'X-Request-Id' => $this->correlation()];
        $first = $this->postJson('/api/v1/client-transfers', $this->input(), $headers);
        $second = $this->postJson('/api/v1/client-transfers', $this->input(), $headers);

        $first->assertCreated()
            ->assertJsonPath('data.type', 'CLIENT_TRANSFER')
            ->assertJsonPath('data.status', 'REQUESTED')
            ->assertJsonPath('data.origin.distributor_id', $this->origin->public_id);
        $second->assertOk();
        self::assertSame(1, ClientTransfer::query()->count());
    }

    public function test_same_idempotency_key_with_different_payload_conflicts(): void
    {
        $service = app(MobilityWorkflowService::class);
        $service->createTransfer($this->origin, $this->input(), 'same-key', $this->correlation());
        $different = $this->input();
        $different['reason'] = 'Otro motivo';

        $this->expectExceptionObject(MobilityException::idempotencyConflict());
        $service->createTransfer($this->origin, $different, 'same-key', $this->correlation());
    }

    public function test_administrative_reassignment_is_atomic_reauthenticated_and_keeps_client_identity(): void
    {
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        $service = app(MobilityWorkflowService::class);
        $batch = $service->createAdministrativeReassignment($manager, [[
            'client_id' => $this->clientId,
            'destination_distributor_id' => $this->recipient->public_id,
            'client_version' => 1,
            'portfolio_version' => 1,
        ]], 'Reorganización autorizada', 'admin-create', $this->correlation());
        $batch = $service->validateAdministrativeReassignment(
            $manager, $batch->id, 1, 'admin-validate', $this->correlation(),
        );
        $token = $this->reauthorize(
            $manager,
            CriticalAction::MOBILITY_ADMINISTRATIVE_REASSIGNMENT,
            $batch::class,
            $batch->id,
        );
        $batch = $service->completeAdministrativeReassignment(
            $manager, $batch->id, 2, $token, 'admin-complete', $this->correlation(),
        );

        self::assertSame(AdministrativeReassignmentStatus::COMPLETED, $batch->status);
        self::assertSame($this->recipient->public_id, $this->clients->lockAssignment($this->clientId)->distributorId);
        self::assertSame(1, $batch->items->count());
        self::assertSame('COMPLETED', $batch->items->firstOrFail()->status);
        self::assertSame(1, DB::table('clients')->where('id', $this->clientId)->count());
        self::assertSame(1, DB::table('outbox_events')->where('type', 'AdministrativeReassignmentCompleted')->count());
    }

    public function test_organizational_changes_fail_closed_without_m02_history_contract(): void
    {
        $manager = $this->user(RoleCode::GENERAL_MANAGER);
        $destinationBranch = Branch::factory()->create();
        $this->expectExceptionObject(MobilityException::dependencyUnavailable('M02_DISTRIBUTOR_BRANCH_ASSIGNMENTS'));
        app(MobilityWorkflowService::class)->createBranchChange($manager, [
            'distributor_id' => $this->origin->public_id,
            'destination_branch_id' => $destinationBranch->id,
            'reason' => 'Cambio autorizado',
        ], 'branch-change', $this->correlation());
    }

    public function test_administrator_is_read_only_and_verifier_cannot_participate(): void
    {
        $service = app(MobilityWorkflowService::class);
        foreach ([RoleCode::ADMINISTRATOR, RoleCode::VERIFIER] as $role) {
            $actor = $this->user($role);
            try {
                $service->createAdministrativeReassignment($actor, [[
                    'client_id' => $this->clientId,
                    'destination_distributor_id' => $this->recipient->public_id,
                    'client_version' => 1,
                    'portfolio_version' => 1,
                ]], 'No autorizado', 'denied-'.$role->value, $this->correlation());
                self::fail("{$role->value} no debe ejecutar cambios.");
            } catch (MobilityException $exception) {
                self::assertSame('MOBILITY_SCOPE_DENIED', $exception->errorCode());
            }
        }
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        return [
            'client_id' => $this->clientId,
            'recipient_distributor_id' => $this->recipient->public_id,
            'client_version' => 1,
            'portfolio_version' => 1,
            'reason' => 'Transferencia solicitada',
        ];
    }

    private function assignment(string $total = '0.0000'): ClientAssignmentSnapshot
    {
        return new ClientAssignmentSnapshot(
            assignmentId: (string) Str::uuid(),
            clientId: $this->clientId,
            clientVersion: 1,
            distributorId: $this->origin->public_id,
            branchId: $this->branch->id,
            portfolioVersion: 1,
            totalDue: $total,
            overdue: '0.0000',
        );
    }

    private function distributor(User $user): DistributorSnapshot
    {
        return new DistributorSnapshot(
            id: $user->public_id,
            internalId: $user->id,
            branchId: $this->branch->id,
            coordinatorId: $user->coordinator_id,
            active: true,
        );
    }

    /** @param array<string, mixed> $extra */
    private function user(RoleCode $role, array $extra = []): User
    {
        $roleModel = Role::query()->where('code', $role->value)->firstOrFail();

        return User::factory()->create([
            'role_id' => $roleModel->id,
            'branch_id' => $role->isGlobal() ? null : $this->branch->id,
            'state' => AccountState::ACTIVE,
            ...$extra,
        ]);
    }

    private function correlation(): string
    {
        return (string) Str::uuid();
    }

    private function reauthorize(User $actor, CriticalAction $action, string $resourceType, string $resourceId): string
    {
        $session = AuthSession::query()->create([
            'user_id' => $actor->id,
            'application' => 'administrativa',
            'device_id' => 'mobility-test',
            'ip_address' => '127.0.0.1',
            'context_version' => $actor->context_version,
            'last_activity_at' => now('UTC'),
            'expires_at' => now('UTC')->addHour(),
            'state' => 'ACTIVE',
        ]);
        $created = $actor->createToken('mobility-tests', ['*'], now('UTC')->addHour());
        $created->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $actor->context_version,
        ])->save();
        $actor->withAccessToken($created->accessToken);
        $plain = Str::random(64);
        $binding = new AuthorizationBinding(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            branchId: $actor->branch_public_id,
            parameters: [],
        );
        ReauthAuthorization::query()->create([
            'user_id' => $actor->id,
            'auth_session_id' => $session->id,
            'requester_user_id' => $actor->id,
            'method' => 'PASSWORD_TOTP',
            'action' => $action->value,
            'resource_type' => $resourceType,
            'record_id' => $resourceId,
            'branch_id' => $actor->branch_public_id,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => $actor->context_version,
            'token_hash' => hash('sha256', $plain),
            'issued_at' => now('UTC'),
            'expires_at' => now('UTC')->addMinutes(5),
        ]);

        return $plain;
    }
}
