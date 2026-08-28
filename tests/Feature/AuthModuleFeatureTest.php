<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthModuleFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_flow_requires_mfa()
    {
        $user = User::factory()->create([
            'email' => 'admin@misvales.com',
            'normalized_email' => 'admin@misvales.com',
            'password' => Hash::make('SuperSecret123!'),
            'state' => 'ACTIVE',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@misvales.com',
            'password' => 'SuperSecret123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'mfa_challenge_token', 'expires_in']);
    }

    public function test_login_flow_fails_with_invalid_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'fake@misvales.com',
            'password' => 'WrongPass!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', 'Credenciales inválidas.');
    }

    public function test_local_demo_can_explicitly_skip_totp_and_passkey(): void
    {
        $this->app->detectEnvironment(fn (): string => 'local');
        config()->set('auth.development_mfa_bypass', true);
        config()->set('services.turnstile.enabled', false);
        User::factory()->create([
            'email' => 'demo@misvales.local',
            'normalized_email' => 'demo@misvales.local',
            'password' => Hash::make('1234'),
            'state' => 'ACTIVE',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'demo@misvales.local',
            'password' => '1234',
        ])->assertOk()->assertJsonPath('development_mfa_bypass', true);
        $challenge = $login->json('mfa_challenge_token');

        $this->postJson('/api/v1/auth/mfa/development/skip', [
            'mfa_challenge_token' => $challenge,
            'factor' => 'TOTP',
        ])->assertOk()->assertJsonPath('next_step', 'PASSKEY');

        $this->postJson('/api/v1/auth/mfa/development/skip', [
            'mfa_challenge_token' => $challenge,
            'factor' => 'PASSKEY',
        ])->assertOk()->assertJsonStructure(['access_token']);
    }

    public function test_mfa_skip_is_not_available_outside_local(): void
    {
        config()->set('auth.development_mfa_bypass', true);

        $this->postJson('/api/v1/auth/mfa/development/skip', [
            'mfa_challenge_token' => 'challenge',
            'factor' => 'TOTP',
        ])->assertNotFound();
    }
}
