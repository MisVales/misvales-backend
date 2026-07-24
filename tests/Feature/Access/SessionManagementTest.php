<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Infrastructure\Persistence\Models\AuthSession;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_own_sessions_with_masked_ips(): void
    {
        $user = User::factory()->create();

        // Crear sesión 1 (Actual)
        $session1 = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'administrativa',
            'device_id' => 'device1',
            'ip_address' => '192.168.1.15',
            'context_version' => 1,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(8),
            'state' => 'ACTIVE',
        ]);

        // Crear sesión 2 (Otra) con IPv6
        $session2 = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'tableta',
            'device_id' => 'device2',
            'ip_address' => '2001:db8:3333:4444:5555:6666:7777:8888',
            'context_version' => 1,
            'last_activity_at' => now(),
            'expires_at' => now()->addHours(8),
            'state' => 'ACTIVE',
        ]);

        $token = $user->createToken('administrativa');
        $token->accessToken->forceFill(['auth_session_id' => $session1->id])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/auth/sessions');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // Check Masked IPs
        $ip1 = collect($data)->firstWhere('id', $session1->id)['ip_address'];
        $ip2 = collect($data)->firstWhere('id', $session2->id)['ip_address'];

        $this->assertEquals('192.168.***.***', $ip1);
        $this->assertEquals('2001:db8:3333:4444:***:***', $ip2);

        // Ensure no tokens are leaked
        $this->assertArrayNotHasKey('access_token', $data[0]);
        $this->assertArrayNotHasKey('refresh_token', $data[0]);

        $currentSessionData = collect($data)->firstWhere('id', $session1->id);
        $this->assertTrue($currentSessionData['is_current']);

        $otherSessionData = collect($data)->firstWhere('id', $session2->id);
        $this->assertFalse($otherSessionData['is_current']);
    }

    public function test_user_can_logout_and_revoke_current_session(): void
    {
        $user = User::factory()->create();

        $session = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'administrativa',
            'state' => 'ACTIVE',
            'expires_at' => now()->addHours(8),
            'last_activity_at' => now(),
        ]);

        $token = $user->createToken('administrativa');
        $token->accessToken->forceFill(['auth_session_id' => $session->id])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->postJson('/api/v1/auth/logout');

        $response->assertStatus(200);
        $this->assertEquals('REVOKED', $session->fresh()->state);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }

    public function test_user_cannot_revoke_others_session(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $mySession = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'administrativa',
            'state' => 'ACTIVE',
            'expires_at' => now()->addHours(8),
            'last_activity_at' => now(),
        ]);

        $otherSession = AuthSession::create([
            'user_id' => $otherUser->id,
            'application' => 'administrativa',
            'state' => 'ACTIVE',
            'expires_at' => now()->addHours(8),
            'last_activity_at' => now(),
        ]);

        $token = $user->createToken('administrativa');
        $token->accessToken->forceFill(['auth_session_id' => $mySession->id])->save();

        // Try to revoke the other user's session
        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->deleteJson('/api/v1/auth/sessions/'.$otherSession->id);

        $response->assertStatus(403); // B10 requires a bound reauthentication before resolving the target.
        $this->assertEquals('ACTIVE', $otherSession->fresh()->state);
    }

    public function test_user_can_revoke_all_other_sessions(): void
    {
        $user = User::factory()->create();

        $session1 = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'administrativa',
            'state' => 'ACTIVE',
            'expires_at' => now()->addHours(8),
            'last_activity_at' => now(),
        ]);

        $session2 = AuthSession::create([
            'user_id' => $user->id,
            'application' => 'tableta',
            'state' => 'ACTIVE',
            'expires_at' => now()->addHours(8),
            'last_activity_at' => now(),
        ]);

        $token = $user->createToken('administrativa');
        $token->accessToken->forceFill([
            'auth_session_id' => $session1->id,
            'context_version' => $user->context_version,
        ])->save();
        $plainReauthToken = bin2hex(random_bytes(32));
        $binding = new AuthorizationBinding(
            CriticalAction::SESSION_REVOKE_OTHERS,
            'auth_sessions',
            'others',
            $user->branch_public_id,
            [],
        );
        ReauthAuthorization::query()->create([
            'user_id' => $user->id,
            'auth_session_id' => $session1->id,
            'requester_user_id' => $user->id,
            'method' => 'PASSWORD_TOTP',
            'action' => $binding->action->value,
            'resource_type' => $binding->resourceType,
            'record_id' => $binding->resourceId,
            'branch_id' => $binding->branchId,
            'parameters_hash' => $binding->parametersHash(),
            'context_version' => $user->context_version,
            'token_hash' => hash('sha256', $plainReauthToken),
            'issued_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->deleteJson('/api/v1/auth/sessions/others', ['reauth_token' => $plainReauthToken]);

        $response->assertStatus(200);
        $this->assertEquals('ACTIVE', $session1->fresh()->state);
        $this->assertEquals('REVOKED', $session2->fresh()->state);
    }
}
