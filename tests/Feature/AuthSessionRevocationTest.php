<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\User;
use App\Services\Auth\SessionTokenIdentifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Tests\TestCase;

final class AuthSessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_identifier_matches_the_hash_persisted_by_sanctum(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $token = $user->createToken('session-hash');

        self::assertSame(
            $token->accessToken->getRawOriginal('token'),
            app(SessionTokenIdentifier::class)->fromPlainTextToken($token->plainTextToken),
        );
    }

    public function test_revoked_legacy_session_rejects_and_deletes_its_existing_token(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        [$token, $session] = $this->issueSession($user, legacyIdentifier: true);
        $session->update(['revoked_at' => now(), 'revocation_reason' => 'REMOTE_REVOKE']);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'INVALID_SESSION');

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_remote_logout_revokes_the_auth_session_and_sanctum_token(): void
    {
        Mail::fake();
        $user = User::factory()->create(['state' => 'ACTIVE']);
        [$currentToken] = $this->issueSession($user);
        [$remoteToken, $remoteSession] = $this->issueSession($user);

        $this->withToken($currentToken->plainTextToken)
            ->deleteJson('/api/v1/me/sessions/'.$remoteSession->id)
            ->assertNoContent();

        self::assertSame('USER_REMOTE_LOGOUT', $remoteSession->fresh()->revocation_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $remoteToken->accessToken->id]);
        $this->forgetAuthenticatedRequest();
        $this->withToken($remoteToken->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->forgetAuthenticatedRequest();
        $this->withToken($currentToken->plainTextToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_password_reset_revokes_every_session_and_token(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'state' => 'ACTIVE',
            'normalized_email' => 'reset-session@misvales.test',
        ]);
        [$token, $session] = $this->issueSession($user);
        $plainResetToken = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainResetToken),
            'requested_ip' => '127.0.0.1',
            'expires_at' => now()->addHour(),
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/auth/password/reset', [
            'email' => $user->normalized_email,
            'token' => $plainResetToken,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        self::assertSame('PASSWORD_RESET', $session->fresh()->revocation_reason);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
        self::assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
    }

    public function test_password_change_keeps_current_session_and_revokes_other_tokens(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'state' => 'ACTIVE',
            'password' => Hash::make('CurrentPassword123!'),
        ]);
        [$currentToken, $currentSession] = $this->issueSession($user);
        [$otherToken, $otherSession] = $this->issueSession($user);

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/v1/me/security/password', [
                'current_password' => 'CurrentPassword123!',
                'new_password' => 'ChangedPassword123!',
                'new_password_confirmation' => 'ChangedPassword123!',
            ])
            ->assertOk();

        self::assertNull($currentSession->fresh()->revoked_at);
        self::assertSame('PASSWORD_CHANGE', $otherSession->fresh()->revocation_reason);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->forgetAuthenticatedRequest();
        $this->withToken($currentToken->plainTextToken)->getJson('/api/v1/me')->assertOk();
        $this->forgetAuthenticatedRequest();
        $this->withToken($otherToken->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    /** @return array{NewAccessToken, AuthSession} */
    private function issueSession(User $user, bool $legacyIdentifier = false): array
    {
        $token = $user->createToken('auth-session-test');
        $session = AuthSession::create([
            'user_id' => $user->id,
            'session_identifier_hash' => $legacyIdentifier
                ? hash('sha256', $token->plainTextToken)
                : $token->accessToken->getRawOriginal('token'),
            'authentication_method' => 'PASSWORD',
            'mfa_method' => 'TOTP',
            'mfa_verified_at' => now(),
            'ip_address' => '127.0.0.1',
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return [$token, $session];
    }

    private function forgetAuthenticatedRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
