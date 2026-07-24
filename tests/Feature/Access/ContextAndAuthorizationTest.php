<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_distributor_gets_mobile_experience_and_branch_scope(): void
    {
        $user = User::factory()->distributor()->create([
            'context_version' => 1,
        ]);

        // Since Sanctum::actingAs doesn't simulate the token in DB, we'll just mock it or assume it passes.
        // Actually the endpoint uses `$user->currentAccessToken()->auth_session_id` to get session data.
        // Let's create a session.
        $session = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'distribuidora',
            'device_id' => 'test_device',
            'ip_address' => '127.0.0.1',
            'context_version' => 1,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(24),
            'state' => 'ACTIVE',
        ]);

        $token = $user->createToken('distribuidora');
        $token->accessToken->forceFill([
            'auth_session_id' => $session->id,
            'context_version' => 1,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/auth/context');

        $response->assertStatus(200);
        $response->assertJsonPath('data.role.code', 'DISTRIBUTOR');
        $response->assertJsonPath('data.experience.code', 'DISTRIBUTOR_MOBILE');
        $response->assertJsonPath('data.experience.layout', 'mobile');
        $response->assertJsonPath('data.scope.type', 'BRANCH');
        $response->assertJsonPath('data.scope.branchId', $user->branch_public_id);
    }

    public function test_admin_gets_admin_experience_and_global_scope(): void
    {
        $user = User::factory()->administrator()->create([
            'context_version' => 1,
        ]);

        $token = $user->createToken('administrativa');
        $token->accessToken->forceFill([
            'context_version' => 1,
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/auth/context');

        $response->assertStatus(200);
        $response->assertJsonPath('data.role.code', 'ADMINISTRATOR');
        $response->assertJsonPath('data.experience.code', 'ADMIN');
        $response->assertJsonPath('data.scope.type', 'GLOBAL');
        $this->assertContains('security.alerts.global.read', $response->json('data.permissions'));
        $this->assertContains('security.audit.global.read', $response->json('data.permissions'));
    }

    public function test_context_version_mismatch_revokes_session_and_returns_401(): void
    {
        // 1. Crear usuario con version 1
        $user = User::factory()->sucursalManager()->create([
            'context_version' => 1,
        ]);

        $token = $user->createToken('administrativa');
        $token->accessToken->forceFill([
            'context_version' => 1, // The token was emitted when context was 1
        ])->save();

        // 2. Change the user's context_version in the DB (simulating a role/permission change)
        $user->forceFill(['context_version' => 2])->save();

        // 3. Request should be rejected by VerifyContextVersionMiddleware
        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/auth/context');

        // Note: I have not yet registered the middleware in the global or route-specific stack.
        // Let's ensure the middleware is active on the route.
        // If it isn't active, this test will fail and I'll add it.
        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'CONTEXT_CHANGED');
    }
}
