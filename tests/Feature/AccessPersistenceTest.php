<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Application\Accounts\DecideAccountRequest;
use App\Modules\Access\Application\Authentication\ConsumeInvitation;
use App\Modules\Access\Application\Authentication\RotateRefreshToken;
use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Domain\Authentication\TokenNotActive;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountRequest;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\OperationalAuthorizationToken;
use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\Access\Infrastructure\Persistence\Models\PasswordHistory;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshTokenFamily;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityEvent;
use Database\Seeders\AccessFoundationSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AccessPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_all_b02_tables_are_migrated(): void
    {
        foreach ([
            'account_requests', 'account_invitations', 'password_histories', 'mfa_credentials',
            'mfa_recovery_codes', 'auth_sessions', 'refresh_token_families', 'refresh_tokens',
            'auth_attempts', 'security_events', 'reauth_authorizations',
            'operational_authorization_tokens', 'outbox_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_normalized_email_is_unique_regardless_of_case(): void
    {
        User::factory()->generalManager()->create(['email' => 'Manager@Example.COM']);

        $this->expectException(QueryException::class);
        User::factory()->generalManager()->create(['email' => 'manager@example.com']);
    }

    public function test_account_request_accepts_only_one_final_decision(): void
    {
        [$requester, $decider] = User::factory()->generalManager()->count(2)->create();
        $role = Role::query()->where('code', RoleCode::COORDINATOR->value)->firstOrFail();
        $request = AccountRequest::query()->create([
            'type' => 'CREATE',
            'target_email' => 'new@example.com',
            'requested_role_id' => $role->id,
            'requested_by' => $requester->id,
            'reason' => 'Cobertura operativa',
            'idempotency_key' => 'request-once',
        ]);

        $service = app(DecideAccountRequest::class);
        $service->execute($request->id, $decider->id, AccountRequestState::APPROVED, 'Autorizada');

        $this->expectException(DomainException::class);
        $service->execute($request->id, $decider->id, AccountRequestState::REJECTED, 'Segundo intento');
    }

    public function test_invitation_can_only_be_consumed_once(): void
    {
        $plainToken = 'opaque-invitation-token';
        $invitation = AccountInvitation::query()->create([
            'user_id' => User::factory()->generalManager()->create()->id,
            'purpose' => 'ACCOUNT_ACTIVATION',
            'token_hash' => hash('sha256', $plainToken),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        app(ConsumeInvitation::class)->execute($plainToken);
        $this->assertSame(TokenState::USED, $invitation->fresh()->state);

        $this->expectException(TokenNotActive::class);
        app(ConsumeInvitation::class)->execute($plainToken);
    }

    public function test_refresh_rotation_leaves_only_one_active_token(): void
    {
        [$family, $token] = $this->createRefreshToken('current-token');

        $newToken = app(RotateRefreshToken::class)->execute(
            'current-token',
            'next-token',
            now()->toImmutable()->addHour(),
        );

        $this->assertSame(TokenState::REPLACED, $token->fresh()->state);
        $this->assertSame(TokenState::ACTIVE, $newToken->state);
        $this->assertSame(1, RefreshToken::query()->where('refresh_token_family_id', $family->id)->where('state', TokenState::ACTIVE)->count());

        $this->expectException(TokenNotActive::class);
        app(RotateRefreshToken::class)->execute('current-token', 'third-token', now()->toImmutable()->addHour());
    }

    public function test_main_change_and_outbox_event_roll_back_together(): void
    {
        $beforeUsers = User::query()->count();

        try {
            DB::transaction(function (): void {
                $user = User::factory()->generalManager()->create();
                OutboxEvent::query()->create([
                    'type' => 'ACCOUNT_CREATED',
                    'payload' => ['user_id' => $user->public_id],
                    'idempotency_key' => 'rollback-event',
                    'available_at' => now(),
                ]);
                throw new RuntimeException('Force rollback');
            });
        } catch (RuntimeException) {
            // La excepción simula un fallo posterior a ambas escrituras.
        }

        $this->assertSame($beforeUsers, User::query()->count());
        $this->assertDatabaseMissing('outbox_events', ['idempotency_key' => 'rollback-event']);
    }

    public function test_sensitive_attributes_are_hidden_from_serialization(): void
    {
        config(['app.key' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=']);

        $models = [
            [new User(['password' => 'hash']), ['password', 'remember_token']],
            [new AccountInvitation(['token_hash' => 'hash']), ['token_hash']],
            [new PasswordHistory(['password_hash' => 'hash']), ['password_hash']],
            [new MfaCredential(['public_key' => 'key', 'encrypted_secret' => 'secret', 'metadata' => []]), ['public_key', 'encrypted_secret', 'metadata']],
            [new AuthSession(['device_id' => 'device', 'ip_address' => '127.0.0.1', 'user_agent' => 'agent']), ['device_id', 'ip_address', 'user_agent']],
            [new RefreshToken(['token_hash' => 'hash']), ['token_hash']],
            [new SecurityEvent(['metadata' => []]), ['metadata']],
            [new ReauthAuthorization(['token_hash' => 'hash']), ['token_hash']],
            [new OperationalAuthorizationToken(['token_hash' => 'hash', 'authorized_fields' => []]), ['token_hash', 'authorized_fields']],
            [new OutboxEvent(['payload' => [], 'last_error' => 'error']), ['payload', 'last_error']],
        ];

        foreach ($models as [$model, $hidden]) {
            $serialized = $model->toArray();
            foreach ($hidden as $attribute) {
                $this->assertArrayNotHasKey($attribute, $serialized);
            }
        }
    }

    public function test_postgresql_enforces_three_active_sessions(): void
    {
        $this->requirePostgreSql();
        $user = User::factory()->generalManager()->create();
        foreach (range(1, 3) as $number) {
            $this->createSession($user, "device-{$number}");
        }

        $this->expectException(QueryException::class);
        $this->createSession($user, 'device-4');
    }

    public function test_postgresql_requires_exact_five_minute_authorizations(): void
    {
        $this->requirePostgreSql();
        $user = User::factory()->generalManager()->create();
        $session = $this->createSession($user, 'device');

        $this->expectException(QueryException::class);
        ReauthAuthorization::query()->create([
            'user_id' => $user->id,
            'auth_session_id' => $session->id,
            'action' => 'accounts.create',
            'token_hash' => hash('sha256', 'reauth'),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(6),
        ]);
    }

    public function test_postgresql_explain_uses_security_indexes(): void
    {
        $this->requirePostgreSql();
        $user = User::factory()->sucursalManager()->create();
        $session = $this->createSession($user, 'index-device');
        AccountInvitation::query()->create([
            'user_id' => $user->id,
            'purpose' => 'ACCOUNT_ACTIVATION',
            'token_hash' => hash('sha256', 'index-invitation'),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        AccountRequest::query()->create([
            'type' => 'CREATE',
            'target_email' => 'index@example.com',
            'requested_role_id' => Role::query()->where('code', RoleCode::COORDINATOR->value)->value('id'),
            'branch_id' => $user->branch_id,
            'requested_by' => $user->id,
            'reason' => 'Validar índice',
            'idempotency_key' => 'index-request',
        ]);
        SecurityEvent::query()->create([
            'actor_user_id' => $user->id,
            'target_user_id' => $user->id,
            'auth_session_id' => $session->id,
            'rule_code' => 'INDEX_TEST',
            'scope' => 'BRANCH',
            'result' => 'SUCCESS',
            'correlation_id' => fake()->uuid(),
            'occurred_at' => now(),
        ]);

        DB::statement('SET LOCAL enable_seqscan = off');
        $this->assertExplainUses("SELECT * FROM users WHERE normalized_email = 'missing@example.com'", 'users_normalized_email_unique');
        $this->assertExplainUses("SELECT * FROM auth_sessions WHERE user_id = {$user->id} AND state = 'ACTIVE' ORDER BY last_activity_at", 'auth_sessions_user_id_state_last_activity_at_index');
        $this->assertExplainUses("SELECT * FROM account_invitations WHERE token_hash = 'missing'", 'account_invitations_token_hash_unique');
        $this->assertExplainUses("SELECT * FROM account_requests WHERE branch_id = {$user->branch_id} AND state = 'PENDING_APPROVAL' ORDER BY created_at", 'account_requests_pending_idx');
        $this->assertExplainUses("SELECT * FROM security_events WHERE target_user_id = {$user->id} ORDER BY occurred_at", 'security_events_target_user_id_occurred_at_index');
    }

    /** @return array{RefreshTokenFamily, RefreshToken} */
    private function createRefreshToken(string $plainToken): array
    {
        $user = User::factory()->generalManager()->create();
        $session = $this->createSession($user, 'refresh-device');
        $family = RefreshTokenFamily::query()->create([
            'auth_session_id' => $session->id,
            'application' => 'INTERNAL_WEB',
            'state' => SessionState::ACTIVE,
            'absolute_expires_at' => now()->addDay(),
        ]);
        $token = RefreshToken::query()->create([
            'refresh_token_family_id' => $family->id,
            'auth_session_id' => $session->id,
            'token_hash' => hash('sha256', $plainToken),
            'state' => TokenState::ACTIVE,
            'issued_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return [$family, $token];
    }

    private function createSession(User $user, string $device): AuthSession
    {
        return AuthSession::query()->create([
            'user_id' => $user->id,
            'application' => 'INTERNAL_WEB',
            'device_id' => $device,
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
            'state' => SessionState::ACTIVE,
            'context_version' => $user->context_version,
        ]);
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint.');
        }
    }

    private function assertExplainUses(string $query, string $index): void
    {
        $plan = collect(DB::select("EXPLAIN {$query}"))
            ->map(fn (object $row): string => $row->{'QUERY PLAN'})
            ->implode("\n");

        $this->assertStringContainsString($index, $plan, $plan);
    }
}
