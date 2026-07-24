<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Application\Accounts\InvitationTokenFactory;
use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\DistributorFinalAuthorizationCompleted;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\OperationalAuthorizationToken;
use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessFoundationSeeder;
use Database\Seeders\InitialGeneralManagerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('b', 32)),
            'access.revocation_cache_store' => 'array',
        ]);
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_initial_general_manager_seeder_is_idempotent_and_keeps_secrets_out_of_outbox(): void
    {
        config([
            'access.initial_general_manager.enabled' => true,
            'access.initial_general_manager.email' => '  PRIMER.GM@Example.COM ',
            'access.initial_general_manager.name' => 'Gerencia Inicial',
        ]);

        $this->seed(InitialGeneralManagerSeeder::class);
        $this->seed(InitialGeneralManagerSeeder::class);

        $user = User::query()->where('normalized_email', 'primer.gm@example.com')->firstOrFail();
        $this->assertSame(AccountState::PENDING_ACTIVATION, $user->state);
        $this->assertSame(RoleCode::GENERAL_MANAGER, $user->role->code);
        $this->assertNull($user->branch_id);
        $this->assertSame(1, AccountInvitation::query()->where('user_id', $user->id)->where('state', TokenState::ACTIVE->value)->count());
        $this->assertSame(1, OutboxEvent::query()->where('type', 'ACCOUNT_INVITATION_PENDING')->count());
        $invitation = AccountInvitation::query()->where('user_id', $user->id)->firstOrFail();
        $reconstructed = app(InvitationTokenFactory::class)
            ->make($invitation->public_id, $user, $invitation->purpose);
        $this->assertSame($invitation->token_hash, hash('sha256', $reconstructed));
        $payload = json_encode(OutboxEvent::query()->firstOrFail()->payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('token', mb_strtolower($payload));
        $this->assertStringNotContainsString('password', mb_strtolower($payload));
    }

    public function test_general_manager_cannot_create_a_distributor_manually(): void
    {
        $manager = User::factory()->generalManager()->create();
        $branch = Branch::factory()->create();
        $fields = ['name' => 'Distribuidora Uno', 'email' => 'dist@example.com', 'role' => RoleCode::DISTRIBUTOR->value, 'branch_id' => $branch->public_id];
        $token = $this->operationalToken($manager, $fields);

        $this->postJson('/api/v1/accounts', [...$fields, 'authorization_token' => $token])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['normalized_email' => 'dist@example.com']);
    }

    public function test_branch_manager_cannot_choose_another_branch_or_request_an_unauthorized_role(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $manager = User::factory()->sucursalManager()->state(['branch_id' => $branch->id])->create();
        $this->actingAs($manager, 'sanctum');

        $this->withHeader('Idempotency-Key', 'branch-create-1')->postJson('/api/v1/account-requests', [
            'name' => 'Coordinación',
            'email' => 'coord@example.com',
            'role' => RoleCode::COORDINATOR->value,
            'branch_id' => $otherBranch->public_id,
            'reason' => 'Alta requerida por operación',
            'reauth_token' => str_repeat('x', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->withHeader('Idempotency-Key', 'branch-create-2')->postJson('/api/v1/account-requests', [
            'name' => 'Administración',
            'email' => 'admin@example.com',
            'role' => RoleCode::ADMINISTRATOR->value,
            'reason' => 'Alta requerida por operación',
            'reauth_token' => str_repeat('x', 64),
        ])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_general_manager_creates_an_internal_account_with_data_bound_authorization(): void
    {
        $manager = User::factory()->generalManager()->create();
        $branch = Branch::factory()->create();
        $fields = ['name' => 'Caja Uno', 'email' => 'caja.uno@example.com', 'role' => RoleCode::CASHIER->value, 'branch_id' => $branch->public_id];
        $token = $this->operationalToken($manager, $fields);

        $this->postJson('/api/v1/accounts', [...$fields, 'authorization_token' => $token])
            ->assertCreated()
            ->assertJsonPath('data.state', AccountState::PENDING_ACTIVATION->value)
            ->assertJsonPath('data.role', RoleCode::CASHIER->value);

        $user = User::query()->where('normalized_email', 'caja.uno@example.com')->firstOrFail();
        $this->assertNull($user->password);
        $this->assertSame($branch->id, $user->branch_id);
        $this->assertDatabaseHas('account_invitations', ['user_id' => $user->id, 'state' => TokenState::ACTIVE->value]);
        $this->assertDatabaseHas('security_events', ['target_user_id' => $user->id, 'rule_code' => 'ACCOUNT_CREATED_DIRECTLY']);
    }

    public function test_branch_request_approved_by_a_different_general_manager_creates_once(): void
    {
        $branch = Branch::factory()->create();
        $branchManager = User::factory()->sucursalManager()->state(['branch_id' => $branch->id])->create();
        $generalManager = User::factory()->generalManager()->create();
        $requestToken = $this->reauthToken($branchManager, 'account.request.create');

        $response = $this->withHeader('Idempotency-Key', 'branch-approved-1')->postJson('/api/v1/account-requests', [
            'name' => 'Verificación Uno',
            'email' => 'verificacion.uno@example.com',
            'role' => RoleCode::VERIFIER->value,
            'reason' => 'Cobertura operativa de la sucursal',
            'reauth_token' => $requestToken,
        ])->assertAccepted();
        $requestId = $response->json('data.public_id');

        $approvalToken = $this->reauthToken($generalManager, 'account.request.approve', $requestId);
        $this->postJson("/api/v1/account-requests/{$requestId}/approve", [
            'reason' => 'Solicitud y alcance comprobados',
            'reauth_token' => $approvalToken,
        ])->assertOk()->assertJsonPath('data.state', AccountRequestState::APPROVED->value);

        $this->postJson("/api/v1/account-requests/{$requestId}/approve", [
            'reason' => 'Reintento idempotente de red',
            'reauth_token' => str_repeat('z', 64),
        ])->assertOk();

        $this->assertSame(1, User::query()->where('normalized_email', 'verificacion.uno@example.com')->count());
        $created = User::query()->where('normalized_email', 'verificacion.uno@example.com')->firstOrFail();
        $this->assertSame($branch->id, $created->branch_id);
        $this->assertSame(RoleCode::VERIFIER, $created->role->code);
        $this->patchJson("/api/v1/accounts/{$created->public_id}", ['role' => RoleCode::CASHIER->value])->assertNotFound();
    }

    public function test_duplicate_distributor_event_creates_exactly_one_account_and_invitation(): void
    {
        $branch = Branch::factory()->create();
        $manager = User::factory()->generalManager()->create();
        $coordinator = User::factory()->coordinator()->state(['branch_id' => $branch->id])->create();
        $event = new DistributorFinalAuthorizationCompleted(
            requestId: 'request-991',
            distributorId: 'distributor-991',
            email: 'autorizada@example.com',
            name: 'Distribuidora Autorizada',
            branchId: $branch->id,
            coordinatorUserId: $coordinator->id,
            authorizedBy: $manager->id,
            initialCreditLine: '15000.00',
            authorizedAt: CarbonImmutable::now(),
            eventKey: 'distribution-final-991',
        );

        event($event);
        event($event);
        $first = User::query()->where('normalized_email', 'autorizada@example.com')->firstOrFail();
        $second = User::query()->where('normalized_email', 'autorizada@example.com')->firstOrFail();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, User::query()->where('normalized_email', 'autorizada@example.com')->count());
        $this->assertSame(1, AccountInvitation::query()->where('user_id', $first->id)->count());
        $this->assertDatabaseCount('processed_domain_events', 1);
        $this->assertDatabaseCount('distributor_access_links', 1);
    }

    public function test_disabling_revokes_every_access_node_before_success_response(): void
    {
        $manager = User::factory()->generalManager()->create();
        $target = User::factory()->administrator()->create();
        $session = $this->authSession($target);
        $family = RefreshTokenFamily::query()->create([
            'auth_session_id' => $session->id,
            'application' => 'admin-web',
            'state' => SessionState::ACTIVE,
            'absolute_expires_at' => now()->addHours(8),
        ]);
        RefreshToken::query()->create([
            'refresh_token_family_id' => $family->id,
            'auth_session_id' => $session->id,
            'token_hash' => hash('sha256', 'refresh-old'),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $target->createToken('old-access');
        AccountInvitation::query()->create([
            'user_id' => $target->id,
            'purpose' => 'ACCOUNT_ACTIVATION',
            'email_hash' => hash('sha256', $target->normalized_email),
            'credential_version' => $target->credential_version,
            'token_hash' => hash('sha256', 'old-invitation'),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $reauth = $this->reauthToken($manager, 'account.disable', $target->public_id);

        $this->postJson("/api/v1/accounts/{$target->public_id}/disable", [
            'reason' => 'Baja administrativa confirmada',
            'reauth_token' => $reauth,
        ])->assertOk()->assertJsonPath('data.state', AccountState::DISABLED->value);

        $target->refresh();
        $this->assertSame(2, $target->context_version);
        $this->assertDatabaseMissing('auth_sessions', ['id' => $session->id, 'state' => SessionState::ACTIVE->value]);
        $this->assertDatabaseMissing('refresh_token_families', ['id' => $family->id, 'state' => SessionState::ACTIVE->value]);
        $this->assertDatabaseMissing('refresh_tokens', ['auth_session_id' => $session->id, 'state' => TokenState::ACTIVE->value]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
        $this->assertDatabaseMissing('account_invitations', ['user_id' => $target->id, 'state' => TokenState::ACTIVE->value]);
        $this->assertSame(2, Cache::store('array')->get("access:user:{$target->id}:context-version"));
    }

    public function test_reactivation_does_not_restore_old_tokens_and_issues_a_new_invitation(): void
    {
        $manager = User::factory()->generalManager()->create();
        $target = User::factory()->cashier()->create();
        $disableToken = $this->reauthToken($manager, 'account.disable', $target->public_id);
        $this->postJson("/api/v1/accounts/{$target->public_id}/disable", ['reason' => 'Deshabilitación de prueba', 'reauth_token' => $disableToken])->assertOk();
        $reactivateToken = $this->reauthToken($manager, 'account.reactivate', $target->public_id);

        $this->postJson("/api/v1/accounts/{$target->public_id}/reactivate", [
            'reason' => 'Reincorporación autorizada',
            'reauth_token' => $reactivateToken,
        ])->assertOk()->assertJsonPath('data.state', AccountState::PENDING_ACTIVATION->value);

        $this->assertNull($target->refresh()->password);
        $this->assertSame(2, $target->credential_version);
        $this->assertDatabaseHas('account_invitations', [
            'user_id' => $target->id,
            'state' => TokenState::ACTIVE->value,
            'purpose' => 'ACCOUNT_REACTIVATION',
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
    }

    public function test_last_active_general_manager_cannot_be_disabled(): void
    {
        $manager = User::factory()->generalManager()->create();
        $token = $this->reauthToken($manager, 'account.disable', $manager->public_id);

        $this->postJson("/api/v1/accounts/{$manager->public_id}/disable", [
            'reason' => 'Intento no permitido',
            'reauth_token' => $token,
        ])->assertStatus(409);

        $this->assertSame(AccountState::ACTIVE, $manager->refresh()->state);
    }

    /** @param array<string, mixed> $fields */
    private function operationalToken(User $user, array $fields): string
    {
        $plain = bin2hex(random_bytes(32));
        $session = $this->authSession($user);
        $binding = new AuthorizationBinding(
            action: CriticalAction::ACCOUNT_CREATE,
            resourceType: User::class,
            resourceId: hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR)),
            branchId: is_string($fields['branch_id'] ?? null) ? $fields['branch_id'] : null,
            parameters: $fields,
            reason: 'Autorización de prueba',
        );
        OperationalAuthorizationToken::query()->create([
            'requester_user_id' => $user->id,
            'authorizer_user_id' => $user->id,
            'executor_user_id' => $user->id,
            'authorizer_session_id' => $session->id,
            'action' => $binding->action->value,
            'resource_type' => $binding->resourceType,
            'resource_id' => $binding->resourceId,
            'branch_id' => $binding->branchId,
            'parameters_hash' => $binding->parametersHash(),
            'reason' => $binding->reason,
            'token_hash' => hash('sha256', $plain),
            'context_version' => $user->context_version,
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
        $this->authenticateSession($user, $session);

        return $plain;
    }

    private function reauthToken(User $user, string $action, ?string $recordId = null): string
    {
        $plain = bin2hex(random_bytes(32));
        $session = $this->authSession($user);
        ReauthAuthorization::query()->create([
            'user_id' => $user->id,
            'auth_session_id' => $session->id,
            'action' => $action,
            'record_type' => $recordId === null ? null : User::class,
            'record_id' => $recordId,
            'branch_id' => $user->branch_id,
            'token_hash' => hash('sha256', $plain),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
        $this->authenticateSession($user, $session);

        return $plain;
    }

    private function authenticateSession(User $user, AuthSession $session): void
    {
        $token = $user->createToken('test-access');
        $token->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $user->context_version,
        ])->save();
        $this->actingAs($user->withAccessToken($token->accessToken), 'sanctum');
    }

    private function authSession(User $user): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->id,
            'application' => 'admin-web',
            'device_id' => (string) Str::uuid(),
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(15),
            'state' => SessionState::ACTIVE,
            'context_version' => $user->context_version,
        ]);
    }
}
