<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OTPHP\TOTP;
use Tests\TestCase;

final class MfaFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AuthSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create([
            'email' => 'gerente@misvales.test',
        ]);
        $this->session = AuthSession::query()->create([
            'user_id' => $this->user->id,
            'application' => 'administrativa',
            'device_id' => 'mfa-feature-device',
            'ip_address' => '127.0.0.1',
            'context_version' => $this->user->context_version,
            'last_activity_at' => now(),
            'expires_at' => now()->addHour(),
            'state' => 'ACTIVE',
        ]);
        $token = $this->user->createToken('administrativa');
        $token->accessToken->forceFill([
            'auth_session_id' => $this->session->id,
            'context_version' => $this->user->context_version,
        ])->save();
        $this->withToken($token->plainTextToken);
    }

    public function test_user_can_setup_and_confirm_totp(): void
    {
        // 1. Iniciar setup
        $response = $this->postJson(route('api.v1.auth.mfa.totp.setup'));
        $response->assertOk();
        $response->assertJsonStructure(['data' => ['secret', 'uri']]);

        $secret = $response->json('data.secret');

        // Generar un código válido para el secreto
        $totp = TOTP::create($secret, 30, 'sha1', 6);
        $code = $totp->now();

        // 2. Confirmar TOTP
        $reauthToken = $this->grant(CriticalAction::MFA_TOTP_ADD);
        $confirmResponse = $this->postJson(route('api.v1.auth.mfa.totp.confirm'), [
            'secret' => $secret,
            'code' => $code,
            'reauth_token' => $reauthToken,
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
        $response = $this->postJson(route('api.v1.auth.mfa.recovery-codes.regenerate'), [
            'reauth_token' => $this->grant(CriticalAction::MFA_RECOVERY_CODES_REGENERATE),
        ]);
        $response->assertOk();

        $codes = $response->json('data.recovery_codes');
        $this->assertCount(10, $codes);

        $this->assertDatabaseCount('mfa_recovery_codes', 10);
    }

    public function test_user_can_request_passkey_options(): void
    {
        $response = $this->postJson(route('api.v1.auth.mfa.passkeys.options'));
        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'challenge',
                'rp',
                'user',
                'pubKeyCredParams',
            ],
        ]);
    }

    private function grant(CriticalAction $action): string
    {
        $plainToken = bin2hex(random_bytes(32));
        $binding = new AuthorizationBinding(
            action: $action,
            resourceType: 'users',
            resourceId: $this->user->public_id,
            branchId: $this->user->branch_public_id,
            parameters: [],
        );
        ReauthAuthorization::query()->create([
            'user_id' => $this->user->id,
            'auth_session_id' => $this->session->id,
            'requester_user_id' => $this->user->id,
            'method' => 'PASSWORD_TOTP',
            'action' => $action->value,
            'resource_type' => $binding->resourceType,
            'record_id' => $binding->resourceId,
            'branch_id' => $binding->branchId,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => $this->user->context_version,
            'token_hash' => hash('sha256', $plainToken),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        return $plainToken;
    }
}
