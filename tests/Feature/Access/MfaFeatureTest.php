<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaRecoveryCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use OTPHP\TOTP;
use Tests\TestCase;

final class MfaFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email' => 'gerente@misvales.test',
        ]);
    }

    public function test_user_can_setup_and_confirm_totp(): void
    {
        $this->actingAs($this->user);

        // 1. Iniciar setup
        $response = $this->postJson(route('api.v1.auth.mfa.totp.setup'));
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['secret', 'uri']]);

        $secret = $response->json('data.secret');

        // Generar un código válido para el secreto
        $totp = TOTP::create($secret, 30, 'sha1', 6);
        $code = $totp->now();

        // 2. Confirmar TOTP
        $confirmResponse = $this->postJson(route('api.v1.auth.mfa.totp.confirm'), [
            'secret' => $secret,
            'code' => $code,
        ]);

        $confirmResponse->assertOk();

        // 3. Verificar base de datos
        $this->assertDatabaseHas('mfa_credentials', [
            'user_id' => $this->user->id,
            'type' => MfaType::TOTP->value,
            'state' => 'ACTIVE',
        ]);
    }

    public function test_user_can_regenerate_recovery_codes(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('api.v1.auth.mfa.recovery-codes.regenerate'));
        $response->assertOk();
        
        $codes = $response->json('data.recovery_codes');
        $this->assertCount(10, $codes);

        $this->assertDatabaseCount('mfa_recovery_codes', 10);
    }

    public function test_user_can_request_passkey_options(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('api.v1.auth.mfa.passkeys.options'));
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'challenge',
                'rp',
                'user',
                'pubKeyCredParams',
            ]
        ]);
    }
}
