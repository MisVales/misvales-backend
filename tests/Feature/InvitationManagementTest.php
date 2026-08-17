<?php

namespace Tests\Feature;

use App\Models\AccountInvitation;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InvitationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['state' => 'ACTIVE']);
        $adminRole = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'default_scope' => 'GLOBAL']);
        $this->admin->roleScopes()->create([
            'role_id' => $adminRole->id,
            'scope_type' => 'GLOBAL',
            'status' => 'ACTIVE',
        ]);
    }

    public function test_can_list_invitations_with_state_filter()
    {
        $user1 = User::factory()->create();
        AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user1->id,
            'created_by_user_id' => $this->admin->id,
            'token_hash' => 'hash1',
            'state' => 'ACTIVE',
            'expires_at' => now()->addDays(2),
        ]);

        $user2 = User::factory()->create();
        AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user2->id,
            'created_by_user_id' => $this->admin->id,
            'token_hash' => 'hash2',
            'state' => 'CONSUMED',
            'expires_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->admin);

        $response = $this->getJson('/api/v1/invitations?state=ACTIVE');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('ACTIVE', $response->json('data.0.state'));

        $response2 = $this->getJson('/api/v1/invitations?state=ACTIVE,CONSUMED');
        $response2->assertStatus(200);
        $this->assertCount(2, $response2->json('data'));
    }

    public function test_revoke_active_invitation_success()
    {
        $user1 = User::factory()->create();
        $invitation = AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user1->id,
            'created_by_user_id' => $this->admin->id,
            'token_hash' => 'hash1',
            'state' => 'ACTIVE',
            'expires_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->admin);
        
        // Simular MFA reciente en sesión
        session(['last_mfa_verification' => now()->timestamp]);

        $response = $this->postJson("/api/v1/invitations/{$invitation->id}/revoke", [
            'reason' => 'Duplicate',
        ]);

        $response->assertStatus(200);
        
        $invitation->refresh();
        $this->assertEquals('REVOKED', $invitation->state);
        $this->assertEquals('Duplicate', $invitation->revocation_reason);
        $this->assertEquals($this->admin->id, $invitation->revoked_by);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'INVITATION_REVOKED',
            'entity_id' => $invitation->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_revoke_consumed_invitation_fails()
    {
        $user1 = User::factory()->create();
        $invitation = AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user1->id,
            'created_by_user_id' => $this->admin->id,
            'token_hash' => 'hash1',
            'state' => 'CONSUMED',
            'expires_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->admin);
        session(['last_mfa_verification' => now()->timestamp]);

        $response = $this->postJson("/api/v1/invitations/{$invitation->id}/revoke", [
            'reason' => 'Already consumed',
        ]);

        $response->assertStatus(409);
    }

    public function test_revoke_requires_recent_mfa()
    {
        $user1 = User::factory()->create();
        $invitation = AccountInvitation::create([
            'id' => Str::uuid(),
            'user_id' => $user1->id,
            'created_by_user_id' => $this->admin->id,
            'token_hash' => 'hash1',
            'state' => 'ACTIVE',
            'expires_at' => now()->addDays(2),
        ]);

        $this->actingAs($this->admin);
        // NO simulamos MFA reciente en la sesión

        $response = $this->postJson("/api/v1/invitations/{$invitation->id}/revoke", [
            'reason' => 'Requires MFA',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'MFA_REAUTH_REQUIRED']);
    }
}
