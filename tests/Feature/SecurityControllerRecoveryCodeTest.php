<?php

namespace Tests\Feature;

use App\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityControllerRecoveryCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_regenerate_recovery_codes_requires_reauth_and_creates_codes()
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password123!'),
        ]);

        $this->actingAs($user);

        // Sin contrase?a actual, debe fallar
        $response = $this->postJson('/api/v1/me/security/recovery-codes', []);
        $response->assertStatus(422);

        // Generar c?digos
        $response = $this->postJson('/api/v1/me/security/recovery-codes', [
            'current_password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'message',
            'recovery_codes',
        ]);

        // Verificar que se guardaron 10 codigos
        $this->assertCount(10, MfaRecoveryCode::where('user_id', $user->id)->get());

        // Simulamos un c?digo antiguo
        MfaRecoveryCode::insert([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'batch_id' => Str::uuid(),
            'code_hash' => hash('sha256', 'old_code_123'),
            'position' => 99,
            'generated_at' => now(),
        ]);

        $this->postJson('/api/v1/me/security/recovery-codes', [
            'current_password' => 'Password123!',
        ]);

        // Debe haber solo 10 (los nuevos)
        $this->assertCount(10, MfaRecoveryCode::where('user_id', $user->id)->get());
        $this->assertDatabaseMissing('mfa_recovery_codes', [
            'code_hash' => hash('sha256', 'old_code_123'),
        ]);
    }
}
