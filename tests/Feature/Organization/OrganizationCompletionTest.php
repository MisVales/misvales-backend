<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Notifications\OrganizationChangeNotification;
use App\Modules\Organization\Infrastructure\Outbox\OrganizationOutboxMessage;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OrganizationCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_branch_assignments_and_global_personnel_support_active_and_historical_filters(): void
    {
        $manager = $this->user('Gerente general');
        $branch = $this->branch($manager, 'TRC-01');
        $cashier = $this->role('cashier');
        $active = $this->user('Personal activo');
        $former = $this->user('Personal anterior');
        $this->assignment($manager, $manager, $this->role('general_manager'));
        $this->assignment($active, $manager, $cashier, $branch);
        $this->assignment($former, $manager, $cashier, $branch, active: false);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/branches/{$branch->id}/assignments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $active->id);

        $this->getJson("/api/v1/branches/{$branch->id}/assignments?include_history=true")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/v1/personnel?branch_id={$branch->id}&role_id={$cashier->id}&assignment_status=REVOKED")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $former->id)
            ->assertJsonPath('data.0.revocation_reason', 'Cambio de personal');
    }

    public function test_branch_manager_personnel_query_is_scoped_and_admin_is_read_only(): void
    {
        $creator = $this->user('Creador');
        $branchManager = $this->user('Gerente de sucursal');
        $administrator = $this->user('Administrador');
        $target = $this->user('Personal');
        $ownBranch = $this->branch($creator, 'TRC-01');
        $otherBranch = $this->branch($creator, 'TRC-02');
        $this->assignment($branchManager, $creator, $this->role('branch_manager'), $ownBranch);
        $this->assignment($administrator, $creator, $this->role('admin'));
        $this->assignment($target, $creator, $this->role('cashier'), $otherBranch);

        Sanctum::actingAs($branchManager);
        $this->getJson('/api/v1/personnel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $branchManager->id);
        $this->getJson("/api/v1/personnel?branch_id={$otherBranch->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_SCOPE_DENIED');

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/personnel')->assertOk();
        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $this->role('cashier')->id,
            'branch_id' => $ownBranch->id,
            'scope' => 'BRANCH',
        ])->assertForbidden();
    }

    public function test_assignment_change_is_audited_published_to_outbox_and_notified(): void
    {
        Notification::fake();
        $manager = $this->user('Gerente general');
        $target = $this->user('Personal');
        $branch = $this->branch($manager, 'TRC-01');
        $this->assignment($manager, $manager, $this->role('general_manager'));
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/users/{$target->id}/assignments", [
            'role_id' => $this->role('verifier')->id,
            'branch_id' => $branch->id,
            'scope' => 'BRANCH',
            'assignment_reason' => 'Cobertura por temporada alta',
        ])->assertCreated();

        $this->assertDatabaseHas('organization_outbox_messages', [
            'event_type' => 'PERSONNEL_ASSIGNED',
            'published_at' => null,
        ]);
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'PERSONNEL_ASSIGNED',
            'actor_user_id' => $manager->id,
            'user_id' => $target->id,
            'outcome' => 'SUCCESS',
        ]);

        $this->artisan('organization:publish-outbox')->assertSuccessful();

        Notification::assertSentTo($target, OrganizationChangeNotification::class);
        self::assertNotNull(OrganizationOutboxMessage::query()->firstOrFail()->published_at);
    }

    public function test_out_of_scope_attempt_is_audited_and_notifies_general_manager_through_outbox(): void
    {
        Notification::fake();
        $generalManager = $this->user('Gerente general');
        $branchManager = $this->user('Gerente de sucursal');
        $ownBranch = $this->branch($generalManager, 'TRC-01');
        $otherBranch = $this->branch($generalManager, 'TRC-02');
        $this->assignment($generalManager, $generalManager, $this->role('general_manager'));
        $this->assignment($branchManager, $generalManager, $this->role('branch_manager'), $ownBranch);
        Sanctum::actingAs($branchManager);

        $this->getJson("/api/v1/branches/{$otherBranch->id}/personnel")
            ->assertForbidden();

        $this->assertDatabaseHas('organization_outbox_messages', [
            'event_type' => 'ORGANIZATION_SCOPE_DENIED',
        ]);
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'ORGANIZATION_SCOPE_DENIED',
            'actor_user_id' => $branchManager->id,
            'outcome' => 'DENIED',
        ]);

        $this->artisan('organization:publish-outbox')->assertSuccessful();
        Notification::assertSentTo($generalManager, OrganizationChangeNotification::class);
    }

    public function test_organization_validation_errors_use_the_standard_contract(): void
    {
        $manager = $this->user('Gerente general');
        $this->assignment($manager, $manager, $this->role('general_manager'));
        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/personnel?assignment_status=UNKNOWN')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['code', 'message', 'fields', 'details', 'request_id']);
    }

    private function user(string $name, string $state = 'ACTIVE'): User
    {
        $email = Str::uuid()->toString().'@example.test';

        return User::factory()->create([
            'name' => $name,
            'email' => $email,
            'normalized_email' => $email,
            'state' => $state,
        ]);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }

    private function branch(User $creator, string $code): BranchRecord
    {
        return BranchRecord::query()->create([
            'id' => Str::uuid()->toString(),
            'code' => $code,
            'name' => "Sucursal {$code}",
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => 0,
            'created_by' => $creator->id,
        ]);
    }

    private function assignment(
        User $user,
        User $actor,
        Role $role,
        ?BranchRecord $branch = null,
        bool $active = true,
    ): void {
        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branch?->id,
            'scope_type' => $branch === null ? 'GLOBAL' : 'BRANCH',
            'status' => $active ? 'ACTIVE' : 'REVOKED',
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now()->subDay(),
            'revoked_by_user_id' => $active ? null : $actor->id,
            'revoked_at' => $active ? null : now(),
            'revocation_reason' => $active ? null : 'Cambio de personal',
        ]);
    }
}
