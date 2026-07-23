<?php

namespace Tests\Feature\Access;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class LoginFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::flushall();
        
        $this->user = User::factory()->create([
            'email' => 'test@misvales.com',
            'normalized_email' => 'test@misvales.com',
            'password' => 'Secret123!',
            'state' => 'ACTIVE',
        ]);
        
        \App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential::create([
            'user_id' => $this->user->id,
            'type' => \App\Modules\Access\Domain\MFA\MfaType::TOTP->value,
            'credential_identifier' => 'test-totp',
            'encrypted_secret' => \Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'state' => 'ACTIVE',
        ]);
    }

    public function test_successful_first_stage_login_returns_mfa_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@misvales.com',
            'password' => 'Secret123!'
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => ['mfa_token', 'expires_at', 'allowed_factors']
            ]);
            
        // Contadores deben estar limpios
        $this->assertNull(Redis::get("throttle:account:15m:test@misvales.com"));
    }

    public function test_failed_login_records_attempt_and_returns_generic_error(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@misvales.com',
            'password' => 'wrong'
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'No fue posible iniciar sesión con la información proporcionada.']);

        $this->assertEquals('1', Redis::get("throttle:account:15m:test@misvales.com"));
    }

    public function test_third_failed_login_triggers_rate_limit_delay(): void
    {
        // 1 y 2
        $this->postJson('/api/v1/auth/login', ['email' => 'test@misvales.com', 'password' => 'wrong']);
        $this->postJson('/api/v1/auth/login', ['email' => 'test@misvales.com', 'password' => 'wrong']);
        
        // 3 -> 429
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'test@misvales.com', 'password' => 'wrong']);
        
        $response->assertStatus(429)
            ->assertHeader('Retry-After', 5)
            ->assertJson(['message' => 'No fue posible iniciar sesión con la información proporcionada.']);
    }

    public function test_fifteen_failures_in_24h_suspends_account(): void
    {
        // Simulate 14 failures recorded manually bypassing the 15m check to test 24h limit
        Redis::set("throttle:account:24h:test@misvales.com", 14);
        
        $this->assertEquals('ACTIVE', $this->user->fresh()->state);
        
        $this->postJson('/api/v1/auth/login', ['email' => 'test@misvales.com', 'password' => 'wrong']);
        
        $this->assertEquals('SECURITY_SUSPENDED', $this->user->fresh()->state);
    }

    public function test_mfa_failures_are_recorded_and_trigger_limits(): void
    {
        // Setup login
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@misvales.com',
            'password' => 'Secret123!'
        ]);
        $token = $loginRes->json('data.mfa_token');
        
        // MFA Fails 3 times -> Invalidate challenge
        $this->postJson('/api/v1/auth/mfa/totp/verify', ['mfa_token' => $token, 'code' => '000000']);
        $this->postJson('/api/v1/auth/mfa/totp/verify', ['mfa_token' => $token, 'code' => '000000']);
        
        $res3 = $this->postJson('/api/v1/auth/mfa/totp/verify', ['mfa_token' => $token, 'code' => '000000']);
        
        $res3->assertStatus(429)
            ->assertHeader('Retry-After', 0)
            ->assertJson(['message' => 'Demasiados intentos fallidos. Desafío MFA invalidado.']);
            
        // Token was consumed/invalidated
        $res4 = $this->postJson('/api/v1/auth/mfa/totp/verify', ['mfa_token' => $token, 'code' => '000000']);
        $res4->assertStatus(401)
            ->assertJson(['message' => 'Sesión MFA expirada o inválida.']);
    }

    public function test_network_subnet_blocking(): void
    {
        for ($i = 0; $i < 100; $i++) {
            // Distribuir entre IPs de la misma subred para no activar el límite de IP (30) primero
            $ip = '192.168.1.' . ($i % 10 + 10);
            
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/v1/auth/login', ['email' => "user{$i}@test.com", 'password' => 'wrong']);
        }
        
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.1.99'])
            ->postJson('/api/v1/auth/login', ['email' => 'test@misvales.com', 'password' => 'wrong']);
            
        $response->assertStatus(429)
            ->assertHeader('Retry-After', 900)
            ->assertJson(['message' => 'El acceso está temporalmente restringido. Inténtalo más tarde o utiliza el proceso de recuperación.']);
    }
}
