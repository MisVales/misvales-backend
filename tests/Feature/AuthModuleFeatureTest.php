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
}
