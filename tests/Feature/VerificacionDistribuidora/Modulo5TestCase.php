<?php

namespace Tests\Feature\VerificacionDistribuidora;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        Auth::forgetGuards();
        foreach ($roles as $role) {
            $alreadyAssigned = $user->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->when($branchId === null, fn ($query) => $query->where('scope_type', 'GLOBAL'))
                ->when($branchId !== null, fn ($query) => $query->where('branch_id', $branchId))
                ->whereHas('role', fn ($query) => $query->where('code', $role))
                ->exists();

            if (! $alreadyAssigned && method_exists($user, 'assignRole')) {
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

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token->plainTextToken,
        ]);
    }
}
