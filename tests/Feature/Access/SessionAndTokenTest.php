<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\PersonalAccessToken;
use OTPHP\TOTP;
use Tests\TestCase;

class SessionAndTokenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store((string) config('access.transient_cache_store'))->clear();

        $this->disableCookieEncryption();

        $this->user = User::factory()->create([
            'email' => 'test@misvales.com',
            'normalized_email' => 'test@misvales.com',
            'password' => 'Secret123!',
            'state' => 'ACTIVE',
        ]);

        MfaCredential::create([
            'user_id' => $this->user->id,
            'type' => MfaType::TOTP->value,
            'credential_identifier' => 'test-totp',
            'encrypted_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'state' => 'ACTIVE',
        ]);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function performFullLogin(string $application = 'administrativa'): array
    {
        $this->withoutExceptionHandling();

        // Advance time by 31 seconds to ensure a new TOTP code and avoid replay protection
        $this->travel(31)->seconds();

        // 1. Valid password
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@misvales.com',
            'password' => 'Secret123!',
            'application' => $application,
        ]);

        $mfaToken = $loginRes->json('data.mfa_token');

        $totpCode = TOTP::create('JBSWY3DPEHPK3PXP')->now();

        // 3. Verify TOTP
        $verifyRes = $this->postJson('/api/v1/auth/mfa/totp/verify', [
            'mfa_token' => $mfaToken,
            'code' => $totpCode,
        ]);

        if ($verifyRes->status() !== 200) {
            dump($verifyRes->json());
        }
        $verifyRes->assertOk();

        return [
            'access_token' => $verifyRes->json('data.access_token'),
            'refresh_token' => $verifyRes->getCookie('__Host-mv_refresh', false)->getValue(),
        ];
    }

    public function test_successful_mfa_emits_tokens_and_creates_session(): void
    {
        $tokens = $this->performFullLogin('administrativa');

        $this->assertDatabaseCount('auth_sessions', 1);
        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $session = AuthSession::first();
        $this->assertEquals('administrativa', $session->application);
        $this->assertEquals('ACTIVE', $session->state);

        $sanctumToken = PersonalAccessToken::first();
        $this->assertEquals($session->id, $sanctumToken->auth_session_id);
    }

    public function test_cannot_exceed_3_active_sessions(): void
    {
        $this->performFullLogin('administrativa');
        $this->performFullLogin('administrativa');
        $this->performFullLogin('administrativa');

        $this->assertDatabaseCount('auth_sessions', 3);

        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@misvales.com',
            'password' => 'Secret123!',
            'application' => 'administrativa',
        ]);

        $mfaToken = $loginRes->json('data.mfa_token');

        // Attempt 4th session
        $verifyRes = $this->postJson('/api/v1/auth/mfa/totp/verify', [
            'mfa_token' => $mfaToken,
            'code' => TOTP::create('JBSWY3DPEHPK3PXP')->now(),
        ]);

        $verifyRes->assertStatus(409)
            ->assertJsonStructure(['message', 'active_sessions']);

        $this->assertDatabaseCount('auth_sessions', 3);
    }

    public function test_refresh_token_rotation(): void
    {
        $tokens = $this->performFullLogin('tableta');
        $oldRefreshToken = $tokens['refresh_token'];

        $refreshRes = $this->call(
            'POST',
            '/api/v1/auth/refresh',
            [],
            ['__Host-mv_refresh' => $oldRefreshToken],
            [],
            ['HTTP_X-APPLICATION-ID' => 'tableta', 'HTTP_ACCEPT' => 'application/json']
        );

        $refreshRes->assertOk();
        $newRefreshToken = $refreshRes->getCookie('__Host-mv_refresh', false)->getValue();

        $this->assertNotEquals($oldRefreshToken, $newRefreshToken);
        $this->assertNotNull($refreshRes->json('data.access_token'));

        // Old token should be marked as used
        $oldHash = hash('sha256', $oldRefreshToken);
        $oldRecord = RefreshToken::where('token_hash', $oldHash)->first();
        $this->assertNotNull($oldRecord->used_at);

        $this->assertDatabaseCount('refresh_tokens', 2);
    }

    public function test_refresh_token_reuse_revokes_family_and_session(): void
    {
        $tokens = $this->performFullLogin('distribuidora');
        $originalToken = $tokens['refresh_token'];

        // Legitimate rotation
        $this->call(
            'POST',
            '/api/v1/auth/refresh',
            [],
            ['__Host-mv_refresh' => $originalToken],
            [],
            ['HTTP_X-APPLICATION-ID' => 'distribuidora', 'HTTP_ACCEPT' => 'application/json']
        );

        // Replay attack: use the old token again
        $replayRes = $this->call(
            'POST',
            '/api/v1/auth/refresh',
            [],
            ['__Host-mv_refresh' => $originalToken],
            [],
            ['HTTP_X-APPLICATION-ID' => 'distribuidora', 'HTTP_ACCEPT' => 'application/json']
        );

        $replayRes->assertStatus(401)
            ->assertJson(['message' => 'Reutilización de token detectada. Sesión terminada por seguridad.']);

        // Check session revoked
        $session = AuthSession::first();
        $this->assertEquals('REVOKED', $session->state);
        $this->assertNotNull($session->revoked_at);

        // Access tokens should be deleted
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
