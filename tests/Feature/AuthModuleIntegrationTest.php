<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Models\AuthSession;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Services\Audit\SecurityAuditService;
use Illuminate\Http\Request;

class AuthModuleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_persistence_and_roles()
    {
        $role = Role::create([
            'id' => Str::uuid(),
            'name' => 'SUPER_ADMIN',
            'code' => 'SUPER_ADMIN',
            'default_scope' => 'GLOBAL',
            'level' => 100
        ]);

        $permission = Permission::create([
            'id' => Str::uuid(),
            'name' => 'sys.manage',
            'code' => 'sys.manage',
            'module' => 'System',
            'action' => 'manage',
            'description' => 'System manage'
        ]);

        $role->permissions()->attach($permission->id, ['id' => Str::uuid(), 'granted_at' => now()]);

        $user = User::factory()->create();

        $this->assertDatabaseHas('roles', ['name' => 'SUPER_ADMIN']);
        $this->assertDatabaseHas('permissions', ['code' => 'sys.manage']);
        $this->assertDatabaseHas('users', ['email' => $user->email]);
    }

    public function test_session_persistence()
    {
        $user = User::factory()->create();

        $session = AuthSession::create([
            'id' => Str::uuid(),
            'user_id' => $user->id,
            'session_identifier_hash' => hash('sha256', 'test-session'),
            'token_hash' => hash('sha256', 'test-token'),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
            'last_activity_at' => now(),
            'expires_at' => now()->addMinutes(60)
        ]);

        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1'
        ]);
        
        $session->update(['revoked_at' => now(), 'revocation_reason' => 'MANUAL_LOGOUT']);
        
        $this->assertNotNull($session->fresh()->revoked_at);
    }

    public function test_security_audit_logging()
    {
        $auditService = app(SecurityAuditService::class);
        $user = User::factory()->create();

        $request = Request::create('/api/test', 'GET');
        $request->server->set('REMOTE_ADDR', '192.168.1.5');
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');

        $auditService->log($request, [
            'event_type' => 'LOGIN_FAILED',
            'severity' => 'WARNING',
            'outcome' => 'FAILURE',
            'user_id' => $user->id,
            'metadata' => ['reason' => 'Invalid password']
        ]);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'LOGIN_FAILED',
            'severity' => 'WARNING',
            'outcome' => 'FAILURE',
            'user_id' => $user->id
        ]);

        $event = SecurityEvent::first();
        $this->assertEquals('192.168.1.5', $event->ip_address);
    }
}
