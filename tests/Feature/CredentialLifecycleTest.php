<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Application\Accounts\InvitationIssuer;
use App\Modules\Access\Application\Accounts\InvitationTokenFactory;
use App\Modules\Access\Application\Authentication\CredentialLifecycleService;
use App\Modules\Access\Application\Authentication\PasswordPolicy;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Accounts\InvitationPurpose;
use App\Modules\Access\Domain\Authentication\TokenState;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Domain\Sessions\SessionState;
use App\Modules\Access\Infrastructure\Persistence\Models\AccountInvitation;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use App\Modules\Access\Infrastructure\Persistence\Models\PasswordHistory;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;
use Tests\TestCase;

class CredentialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'access.revocation_cache_store' => 'array',
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 8192,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
            'hashing.argon.verify' => true,
        ]);
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_invitation_inspection_and_completion_activate_without_creating_a_session(): void
    {
        $user = User::factory()->cashier()->create([
            'state' => AccountState::PENDING_ACTIVATION,
            'password' => null,
            'email_verified_at' => null,
            'mfa_enrolled_at' => null,
        ]);
        [$invitation, $plain] = $this->issue($user, InvitationPurpose::ACCOUNT_ACTIVATION);

        $inspection = $this->postJson('/api/v1/auth/invitations/inspect', ['token' => $plain])
            ->assertOk()
            ->assertJsonMissing(['token_hash'])
            ->json('data');

        $response = $this->postJson('/api/v1/auth/invitations/complete', [
            'exchange_token' => $inspection['exchange_token'],
            'password' => 'Segura Única 42!',
            'password_confirmation' => 'Segura Única 42!',
            'mfa' => ['type' => 'TOTP', 'secret' => self::TOTP_SECRET, 'code' => $this->totpCode()],
        ])->assertOk()
            ->assertJsonPath('data.confirmation_required', true)
            ->assertJsonPath('data.login_required', false);

        $this->assertCount(10, $response->json('data.recovery_codes'));
        $this->assertSame(AccountState::PENDING_ACTIVATION, $user->refresh()->state);
        $this->postJson('/api/v1/auth/invitations/complete', [
            'exchange_token' => $inspection['exchange_token'],
            'recovery_codes_confirmed' => true,
        ])->assertOk()
            ->assertJsonPath('data.confirmation_required', false)
            ->assertJsonPath('data.login_required', true)
            ->assertJsonMissingPath('data.recovery_codes');

        $user->refresh();
        $this->assertSame(AccountState::ACTIVE, $user->state);
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->mfa_enrolled_at);
        $this->assertDatabaseHas('account_invitations', ['id' => $invitation->id, 'state' => TokenState::USED->value]);
        $this->assertDatabaseCount('auth_sessions', 0);
        $this->assertDatabaseCount('mfa_recovery_codes', 10);
        $this->assertDatabaseCount('password_histories', 1);
        $outbox = json_encode(OutboxEvent::query()->pluck('payload'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Segura Única 42!', $outbox);
        $this->assertStringNotContainsString(self::TOTP_SECRET, $outbox);
        foreach ($response->json('data.recovery_codes') as $code) {
            $this->assertStringNotContainsString($code, $outbox);
        }

        $this->postJson('/api/v1/auth/invitations/complete', [
            'exchange_token' => $inspection['exchange_token'],
            'recovery_codes_confirmed' => true,
        ])->assertStatus(409);
    }

    public function test_password_policy_rejects_weak_identity_compromised_and_recent_passwords(): void
    {
        $user = User::factory()->create(['name' => 'Persona Visible', 'email' => 'persona@example.com', 'password' => null]);
        $policy = app(PasswordPolicy::class);

        foreach (['Corta1!', 'password123!', 'persona@example.com A1!', 'Persona Visible A1!'] as $password) {
            try {
                $policy->validateAndNormalize($user, $password);
                $this->fail("Password should have been rejected: {$password}");
            } catch (AccessRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }

        $recent = 'Historial Seguro 89!';
        PasswordHistory::query()->create(['user_id' => $user->id, 'password_hash' => Hash::make($recent), 'recorded_at' => now()]);
        $this->expectException(AccessRuleViolation::class);
        $policy->validateAndNormalize($user, $recent);
    }

    public function test_authenticated_change_revokes_all_sessions_and_never_returns_credentials(): void
    {
        $user = User::factory()->administrator()->create([
            'password' => Hash::make('Anterior Segura 41!'),
            'mfa_enrolled_at' => now(),
        ]);
        $session = $this->authSession($user);
        $plainReauth = bin2hex(random_bytes(32));
        ReauthAuthorization::query()->create([
            'user_id' => $user->id,
            'auth_session_id' => $session->id,
            'action' => 'password.change',
            'record_type' => User::class,
            'record_id' => $user->public_id,
            'token_hash' => hash('sha256', $plainReauth),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
        $user->createToken('current-access');
        $access = $user->createToken('reauthenticated-access');
        $access->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => $user->context_version,
        ])->save();
        $this->actingAs($user->withAccessToken($access->accessToken), 'sanctum');

        $response = $this->postJson('/api/v1/auth/password/change', [
            'password' => 'Nueva Segura 84!',
            'password_confirmation' => 'Nueva Segura 84!',
            'reauth_token' => $plainReauth,
        ])->assertOk();

        $response->assertJsonMissing(['password', 'hash', 'token']);
        $user->refresh();
        $this->assertTrue(Hash::check('Nueva Segura 84!', $user->password));
        $this->assertSame(2, $user->credential_version);
        $this->assertSame(2, $user->context_version);
        $this->assertDatabaseHas('auth_sessions', ['id' => $session->id, 'state' => SessionState::REVOKED->value]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_recovery_has_a_fixed_public_response_and_completion_revokes_access(): void
    {
        $user = User::factory()->administrator()->create([
            'password' => Hash::make('Anterior Segura 61!'),
            'mfa_enrolled_at' => now(),
        ]);
        MfaCredential::query()->create([
            'user_id' => $user->id,
            'type' => MfaType::TOTP,
            'credential_identifier' => hash('sha256', self::TOTP_SECRET),
            'encrypted_secret' => Crypt::encryptString(self::TOTP_SECRET),
            'state' => 'ACTIVE',
        ]);
        $session = $this->authSession($user);

        $missing = $this->postJson('/api/v1/auth/recovery/password', ['email' => 'missing@example.com'])->assertAccepted()->json('message');
        $existing = $this->postJson('/api/v1/auth/recovery/password', ['email' => $user->email])->assertAccepted()->json('message');
        $this->postJson('/api/v1/auth/recovery/password', ['email' => $user->email])->assertAccepted();
        $this->assertSame(CredentialLifecycleService::GENERIC_RECOVERY_RESPONSE, $missing);
        $this->assertSame($missing, $existing);
        $this->assertSame(1, AccountInvitation::query()->where('user_id', $user->id)->where('purpose', InvitationPurpose::PASSWORD_RECOVERY->value)->where('state', TokenState::ACTIVE->value)->count());
        $this->assertDatabaseCount('outbox_events', 1);

        $invitation = AccountInvitation::query()->where('user_id', $user->id)->where('purpose', InvitationPurpose::PASSWORD_RECOVERY->value)->firstOrFail();
        $plain = app(InvitationTokenFactory::class)->make($invitation->public_id, $user, InvitationPurpose::PASSWORD_RECOVERY);
        $this->postJson('/api/v1/auth/recovery/password/complete', [
            'token' => $plain,
            'password' => 'Recuperada Segura 71!',
            'password_confirmation' => 'Recuperada Segura 71!',
            'factor_type' => 'TOTP',
            'factor_value' => $this->totpCode(),
        ])->assertOk()->assertJsonMissing(['password', 'token', 'factor_value']);

        $this->assertTrue(Hash::check('Recuperada Segura 71!', $user->refresh()->password));
        $this->assertDatabaseHas('account_invitations', ['id' => $invitation->id, 'state' => TokenState::USED->value]);
        $this->assertDatabaseHas('auth_sessions', ['id' => $session->id, 'state' => SessionState::REVOKED->value]);
    }

    public function test_expired_revoked_and_wrong_purpose_tokens_are_rejected(): void
    {
        $user = User::factory()->cashier()->create(['state' => AccountState::PENDING_ACTIVATION, 'password' => null]);
        foreach ([TokenState::REVOKED, TokenState::ACTIVE] as $index => $state) {
            $token = "invalid-token-{$index}-".str_repeat('x', 32);
            AccountInvitation::query()->create([
                'user_id' => $user->id,
                'purpose' => $index === 0 ? InvitationPurpose::ACCOUNT_ACTIVATION : InvitationPurpose::PASSWORD_RECOVERY,
                'token_hash' => hash('sha256', $token),
                'state' => $state,
                'issued_at' => now()->subHour(),
                'expires_at' => $index === 0 ? now()->addHour() : now()->subMinute(),
                'revoked_at' => $state === TokenState::REVOKED ? now() : null,
            ]);
            $this->postJson('/api/v1/auth/invitations/inspect', ['token' => $token])->assertStatus(409);
        }
    }

    public function test_administrative_recovery_requires_new_mfa_and_returns_new_recovery_codes_once(): void
    {
        $user = User::factory()->cashier()->create([
            'state' => AccountState::PENDING_ACTIVATION,
            'password' => null,
            'credential_version' => 2,
            'mfa_enrolled_at' => null,
        ]);
        [$invitation, $plain] = $this->issue($user, InvitationPurpose::ACCOUNT_RECOVERY);

        $response = $this->postJson('/api/v1/auth/recovery/password/complete', [
            'token' => $plain,
            'password' => 'Administrativa Segura 73!',
            'password_confirmation' => 'Administrativa Segura 73!',
            'mfa' => ['type' => 'TOTP', 'secret' => self::TOTP_SECRET, 'code' => $this->totpCode()],
        ])->assertOk()->assertJsonPath('data.login_required', true);

        $this->assertCount(10, $response->json('data.recovery_codes'));
        $this->assertSame(AccountState::ACTIVE, $user->refresh()->state);
        $this->assertDatabaseHas('account_invitations', ['id' => $invitation->id, 'state' => TokenState::USED->value]);
        $this->assertDatabaseCount('mfa_recovery_codes', 10);
    }

    public function test_argon2id_parameters_are_bounded_for_the_environment(): void
    {
        $started = microtime(true);
        $hash = Hash::make('Carga Segura 52!');
        $elapsed = microtime(true) - $started;
        $info = password_get_info($hash);

        $this->assertSame('argon2id', $info['algoName']);
        $this->assertSame(8192, $info['options']['memory_cost']);
        $this->assertSame(1, $info['options']['time_cost']);
        $this->assertLessThan(2.0, $elapsed);
    }

    /** @return array{AccountInvitation,string} */
    private function issue(User $user, InvitationPurpose $purpose): array
    {
        $invitation = app(InvitationIssuer::class)->issue($user, $purpose);
        $plain = app(InvitationTokenFactory::class)->make($invitation->public_id, $user, $purpose);

        return [$invitation, $plain];
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

    private function totpCode(): string
    {
        return TOTP::create(self::TOTP_SECRET, 30, 'sha1', 6)->now();
    }
}
