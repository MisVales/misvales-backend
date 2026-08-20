<?php

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthRefreshCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_uses_http_only_cookie_instead_of_request_body(): void
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $accessToken = $user->createToken('test-access');
        $accessToken->accessToken->forceFill(['expires_at' => now()->addMinutes(5)])->save();
        $session = AuthSession::create([
            'user_id' => $user->id,
            'session_identifier_hash' => $accessToken->accessToken->getRawOriginal('token'),
            'authentication_method' => 'PASSWORD',
            'mfa_method' => 'TOTP',
            'mfa_verified_at' => now(),
            'ip_address' => '127.0.0.1',
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        $refreshToken = Str::random(80);

        Cache::put('auth_refresh_'.hash('sha256', $refreshToken), [
            'user_id' => $user->id,
            'session_id' => $session->id,
            'access_token_id' => $accessToken->accessToken->id,
        ], now()->addHour());

        $response = $this
            ->withoutMiddleware(EncryptCookies::class)
            ->withUnencryptedCookie('misvales_refresh', $refreshToken)
            ->withCredentials()
            ->postJson('/api/v1/auth/refresh');

        $response
            ->assertOk()
            ->assertJsonStructure(['message', 'access_token', 'expires_in'])
            ->assertJsonMissing(['refresh_token' => $refreshToken]);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessToken->accessToken->id]);
        $this->assertDatabaseHas('auth_sessions', [
            'id' => $session->id,
            'session_identifier_hash' => hash('sha256', Str::after($response->json('access_token'), '|')),
        ]);
    }

    public function test_refresh_rejects_missing_cookie_even_if_body_contains_a_token(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => Str::random(80)])
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SESSION');
    }

    public function test_refresh_rejects_a_credentialed_request_from_an_untrusted_origin(): void
    {
        $this->withHeader('Origin', 'https://untrusted.example')
            ->withUnencryptedCookie('misvales_refresh', Str::random(80))
            ->postJson('/api/v1/auth/refresh')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ORIGIN_NOT_ALLOWED');
    }

    public function test_refresh_accepts_an_explicitly_configured_origin(): void
    {
        config()->set('cors.allowed_origins', ['https://expert-damages-voices-rough.trycloudflare.com']);
        config()->set('production.frontend_url', 'https://expert-damages-voices-rough.trycloudflare.com');

        $this->withHeader('Origin', 'https://expert-damages-voices-rough.trycloudflare.com')
            ->postJson('/api/v1/auth/refresh')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SESSION');
    }
}
