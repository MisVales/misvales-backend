<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Application\Authorization\OperationalAuthorizationService;
use App\Modules\Access\Application\Authorization\PasskeyAssertionValidator;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\OperationalAuthorizationToken;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\PersonalAccessToken;
use OTPHP\TOTP;
use RuntimeException;
use Tests\TestCase;

final class B10ReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AuthSession $session;

    private string $accessToken;

    private PersonalAccessToken $accessTokenModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'password' => 'Correct-password-123!',
            'state' => 'ACTIVE',
            'context_version' => 3,
        ]);
        $this->session = AuthSession::query()->create([
            'user_id' => $this->user->id,
            'application' => 'administrativa',
            'device_id' => 'random-device-cookie',
            'ip_address' => '127.0.0.1',
            'context_version' => 3,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(8),
            'state' => 'ACTIVE',
        ]);
        $createdToken = $this->user->createToken('administrativa', ['*'], now()->addMinutes(10));
        $createdToken->accessToken->forceFill([
            'auth_session_id' => $this->session->id,
            'context_version' => 3,
        ])->save();
        $this->accessToken = $createdToken->plainTextToken;
        $this->accessTokenModel = $createdToken->accessToken;
        $this->user->withAccessToken($this->accessTokenModel);
        MfaCredential::query()->create([
            'user_id' => $this->user->id,
            'type' => MfaType::TOTP->value,
            'credential_identifier' => 'totp-'.$this->user->id,
            'encrypted_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'state' => 'ACTIVE',
        ]);
        Redis::shouldReceive('command')->andReturn('OK');
    }

    public function test_password_and_totp_issue_only_a_five_minute_single_use_bound_authorization(): void
    {
        $response = $this->withToken($this->accessToken)->postJson('/api/v1/auth/reauthenticate', [
            'method' => 'PASSWORD_TOTP',
            'action' => 'sessions.revoke',
            'resource_type' => 'auth_sessions',
            'resource_id' => '42',
            'parameters' => ['fields' => ['amount'], 'amount' => 125],
            'password' => 'Correct-password-123!',
            'totp_code' => TOTP::create('JBSWY3DPEHPK3PXP')->now(),
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.refresh_token');
        $plainToken = $response->json('data.authorization_token');
        $this->assertIsString($plainToken);
        $authorization = ReauthAuthorization::query()->sole();
        $this->assertSame(hash('sha256', $plainToken), $authorization->token_hash);
        $this->assertLessThanOrEqual(300, $authorization->issued_at->diffInSeconds($authorization->expires_at));
        $this->assertSame($this->session->id, $authorization->auth_session_id);
        $this->assertSame(3, $authorization->context_version);

        $binding = new AuthorizationBinding(
            CriticalAction::SESSION_REVOKE,
            'auth_sessions',
            '42',
            null,
            ['amount' => 125, 'fields' => ['amount']],
        );
        $this->app->make(TemporaryAuthorization::class)->consume($this->user, $plainToken, $binding);
        $this->assertNotNull($authorization->refresh()->used_at);

        $this->expectException(AccessRuleViolation::class);
        $this->app->make(TemporaryAuthorization::class)->consume($this->user, $plainToken, $binding);
    }

    public function test_critical_action_without_authorization_is_rejected_without_side_effects(): void
    {
        $otherSession = AuthSession::query()->create([
            'user_id' => $this->user->id,
            'application' => 'administrativa',
            'context_version' => 3,
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
            'state' => 'ACTIVE',
        ]);

        $this->withToken($this->accessToken)
            ->deleteJson('/api/v1/auth/sessions/'.$otherSession->id)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');

        $this->assertSame('ACTIVE', $otherSession->refresh()->state);
    }

    public function test_authorization_rejects_other_session_action_resource_parameters_context_and_expiration(): void
    {
        $binding = new AuthorizationBinding(
            CriticalAction::SESSION_REVOKE,
            'auth_sessions',
            '42',
            null,
            ['field' => 'amount'],
        );
        $plainToken = 'a-valid-looking-token-with-at-least-thirty-two-characters';
        ReauthAuthorization::query()->create([
            'user_id' => $this->user->id,
            'auth_session_id' => $this->session->id,
            'requester_user_id' => $this->user->id,
            'method' => 'PASSWORD_TOTP',
            'action' => $binding->action->value,
            'resource_type' => $binding->resourceType,
            'record_id' => $binding->resourceId,
            'branch_id' => null,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => 3,
            'token_hash' => hash('sha256', $plainToken),
            'issued_at' => now()->subMinutes(6),
            'expires_at' => now()->subMinute(),
        ]);

        foreach ([
            new AuthorizationBinding(CriticalAction::SESSION_REVOKE_OTHERS, 'auth_sessions', '42', null, ['field' => 'amount']),
            new AuthorizationBinding(CriticalAction::SESSION_REVOKE, 'auth_sessions', '99', null, ['field' => 'amount']),
            new AuthorizationBinding(CriticalAction::SESSION_REVOKE, 'auth_sessions', '42', null, ['field' => 'total']),
        ] as $wrongBinding) {
            try {
                $this->app->make(TemporaryAuthorization::class)->consume($this->user, $plainToken, $wrongBinding);
                $this->fail('A mismatched authorization binding was accepted.');
            } catch (AccessRuleViolation $exception) {
                $this->assertSame('REAUTHENTICATION_REQUIRED', $exception->errorCode());
            }
        }

        $this->expectException(AccessRuleViolation::class);
        $this->app->make(TemporaryAuthorization::class)->consume($this->user, $plainToken, $binding);
    }

    public function test_recovery_code_method_cannot_create_critical_authorization(): void
    {
        $this->withToken($this->accessToken)->postJson('/api/v1/auth/reauthenticate', [
            'method' => 'RECOVERY_CODE',
            'action' => 'password.change',
            'resource_id' => $this->user->public_id,
            'recovery_code' => 'irrelevant',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('reauth_authorizations', 0);
    }

    public function test_passkey_challenge_is_bound_and_single_use(): void
    {
        MfaCredential::query()->create([
            'user_id' => $this->user->id,
            'type' => MfaType::PASSKEY->value,
            'credential_identifier' => 'credential-id',
            'public_key' => base64_encode('test-key'),
            'state' => 'ACTIVE',
        ]);
        $this->app->bind(PasskeyAssertionValidator::class, fn () => new class implements PasskeyAssertionValidator
        {
            public function validate(User $user, array $assertion, string $challenge): bool
            {
                return $challenge !== '' && $assertion['id'] === 'credential-id';
            }
        });
        $payload = [
            'method' => 'PASSKEY',
            'action' => 'password.change',
            'resource_type' => 'users',
            'resource_id' => $this->user->public_id,
        ];
        $challenge = $this->withToken($this->accessToken)
            ->postJson('/api/v1/auth/reauthenticate', $payload)
            ->assertStatus(202)
            ->json('data');
        $assertion = [
            'id' => 'credential-id',
            'rawId' => 'credential-id',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => 'client-data',
                'authenticatorData' => 'authenticator-data',
                'signature' => 'signature',
            ],
        ];
        $completedPayload = $payload + [
            'challenge_id' => $challenge['challenge_id'],
            'assertion' => $assertion,
        ];

        $this->withToken($this->accessToken)
            ->postJson('/api/v1/auth/reauthenticate', $completedPayload)
            ->assertOk();
        $this->withToken($this->accessToken)
            ->postJson('/api/v1/auth/reauthenticate', $completedPayload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'REAUTHENTICATION_FAILED');
    }

    public function test_requester_cannot_self_authorize_and_operational_token_is_exactly_bound(): void
    {
        $service = $this->app->make(OperationalAuthorizationService::class);
        $operation = new AuthorizationBinding(
            CriticalAction::FINANCIAL_ADJUSTMENT,
            'payments',
            'payment-10',
            null,
            ['fields' => ['amount'], 'amount' => 500],
            'Corrección documentada',
        );

        try {
            $service->authorize(
                $this->user,
                $this->user,
                $this->user,
                $this->session,
                $operation,
                'unused',
            );
            $this->fail('Self-authorization was accepted.');
        } catch (AccessRuleViolation $exception) {
            $this->assertSame('SEPARATION_OF_DUTIES_REQUIRED', $exception->errorCode());
        }

        $requester = User::factory()->create();
        $executor = User::factory()->create();
        $reauthPlain = 'operational-reauth-token-with-enough-random-characters';
        $reauthBinding = new AuthorizationBinding(
            CriticalAction::OPERATIONAL_AUTHORIZE,
            'payments',
            'payment-10',
            null,
            $operation->parameters,
            $operation->reason,
        );
        ReauthAuthorization::query()->create([
            'user_id' => $this->user->id,
            'auth_session_id' => $this->session->id,
            'requester_user_id' => $this->user->id,
            'method' => 'PASSKEY',
            'action' => $reauthBinding->action->value,
            'resource_type' => $reauthBinding->resourceType,
            'record_id' => $reauthBinding->resourceId,
            'branch_id' => null,
            'parameters_hash' => $reauthBinding->parametersHash(),
            'context_version' => 3,
            'reason' => $reauthBinding->reason,
            'token_hash' => hash('sha256', $reauthPlain),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
        $beforeSessions = AuthSession::query()->count();
        $beforeAccessTokens = PersonalAccessToken::query()->count();
        $issued = $service->authorize(
            $requester,
            $this->user,
            $executor,
            $this->session,
            $operation,
            $reauthPlain,
        );
        $this->assertSame($beforeSessions, AuthSession::query()->count());
        $this->assertSame($beforeAccessTokens, PersonalAccessToken::query()->count());
        $this->assertSame(
            hash('sha256', $issued['operational_token']),
            OperationalAuthorizationToken::query()->sole()->token_hash,
        );

        foreach ([
            new AuthorizationBinding(CriticalAction::FINANCIAL_ADJUSTMENT, 'payments', 'other', null, $operation->parameters, $operation->reason),
            new AuthorizationBinding(CriticalAction::FINANCIAL_ADJUSTMENT, 'payments', 'payment-10', null, ['fields' => ['total']], $operation->reason),
        ] as $wrongBinding) {
            try {
                $service->consume($executor, $issued['operational_token'], $wrongBinding);
                $this->fail('Operational token accepted a mismatched binding.');
            } catch (AccessRuleViolation $exception) {
                $this->assertSame('OPERATIONAL_AUTHORIZATION_REQUIRED', $exception->errorCode());
            }
        }

        DB::transaction(fn () => $service->consume($executor, $issued['operational_token'], $operation));
        $this->assertNotNull(OperationalAuthorizationToken::query()->sole()->used_at);
    }

    public function test_failed_protected_transaction_does_not_spend_authorization(): void
    {
        $binding = new AuthorizationBinding(CriticalAction::PASSWORD_CHANGE, null, $this->user->public_id, null, []);
        $plainToken = 'transactional-reauth-token-with-enough-randomness';
        $authorization = ReauthAuthorization::query()->create([
            'user_id' => $this->user->id,
            'auth_session_id' => $this->session->id,
            'requester_user_id' => $this->user->id,
            'method' => 'PASSWORD_TOTP',
            'action' => $binding->action->value,
            'resource_type' => null,
            'record_id' => $binding->resourceId,
            'branch_id' => null,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => 3,
            'token_hash' => hash('sha256', $plainToken),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        try {
            DB::transaction(function () use ($plainToken, $binding): void {
                $this->app->make(TemporaryAuthorization::class)->consume($this->user, $plainToken, $binding);
                throw new RuntimeException('Simulated protected action failure.');
            });
        } catch (RuntimeException) {
            // Expected rollback.
        }

        $this->assertNull($authorization->refresh()->used_at);
    }
}
