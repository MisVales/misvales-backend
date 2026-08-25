<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TurnstileVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.turnstile.enabled', true);
    }

    public function test_login_can_run_without_turnstile_when_disabled(): void
    {
        Config::set('services.turnstile.enabled', false);
        Config::set('services.turnstile.secret', null);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@misvales.com',
            'password' => 'PasswordSegura123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_case_c_inconsistency_server_error_when_token_sent_but_no_secret_configured()
    {
        Config::set('services.turnstile.secret', null);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@misvales.com',
            'password' => 'PasswordSegura123!',
            'turnstile_token' => 'some-token',
        ]);

        $response->assertStatus(500)
            ->assertJsonPath('error.code', 'CONFIG_ERROR');
    }

    public function test_case_d_inconsistency_fails_when_secret_configured_but_token_missing()
    {
        Config::set('services.turnstile.secret', 'test-secret-key');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@misvales.com',
            'password' => 'PasswordSegura123!',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TURNSTILE_REQUIRED');
    }

    public function test_case_b_login_fails_when_turnstile_token_is_invalid()
    {
        Config::set('services.turnstile.secret', 'test-secret-key');

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
                'error-codes' => ['invalid-input-response'],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'usuario@misvales.com',
            'password' => 'PasswordSegura123!',
            'turnstile_token' => 'invalid-or-expired-token',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_TURNSTILE');
    }

    public function test_forgot_password_fails_when_turnstile_secret_configured_but_token_missing()
    {
        Config::set('services.turnstile.secret', 'test-secret-key');

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'usuario@misvales.com',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'TURNSTILE_REQUIRED');
    }

    public function test_forgot_password_succeeds_when_turnstile_token_valid()
    {
        Config::set('services.turnstile.secret', 'test-secret-key');

        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'usuario@misvales.com',
            'turnstile_token' => 'valid-token',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message']);
    }
}
