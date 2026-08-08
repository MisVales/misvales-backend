<?php
namespace Tests\Feature\VerificacionDistribuidora;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use App\Models\AuthSession;
use Illuminate\Support\Str;

class SeguridadModulo5Test extends Modulo5TestCase {
    public function test_mfa_incompleto_bloquea_peticion() {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        
        AuthSession::create([
            'user_id' => $user->id,
            'session_identifier_hash' => hash('sha256', $token),
            'mfa_verified_at' => null // MFA no completado
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/distributor-applications/'.Str::uuid().'/assign-verifier');

        $response->assertStatus(403)->assertJsonPath('error', 'INVALID_MFA');
    }
}
