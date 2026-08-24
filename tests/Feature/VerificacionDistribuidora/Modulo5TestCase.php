<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Models\AuthSession;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class Modulo5TestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles and permissions for tests
        $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
    }

    protected function actingAsMfaUser(User $user, array $roles = [], ?string $branchId = null)
    {
        if ($branchId !== null && ! Branch::query()->whereKey($branchId)->exists()) {
            Branch::factory()->create(['id' => $branchId]);
        }
        foreach ($roles as $role) {
            if (method_exists($user, 'assignRole')) {
                $user->assignRole($role, $branchId);
            }
        }

        $token = $user->createToken('test-token');
        $tokenHash = hash('sha256', $token->plainTextToken);

        AuthSession::create([
            'user_id' => $user->id,
            'session_identifier_hash' => $tokenHash,
            'device_id' => 'test-device',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'expires_at' => now()->addMinutes(60),
            'mfa_verified_at' => now(), // Bypasses MFA check
            'last_activity_at' => now(),
        ]);

        // Cada llamada HTTP debe volver a resolver el bearer token actual. Laravel
        // conserva los guards resueltos dentro de la misma instancia de prueba.
        app('auth')->forgetGuards();

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);
    }
}
