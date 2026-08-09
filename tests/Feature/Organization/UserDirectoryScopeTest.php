<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserDirectoryScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_branch_manager_only_sees_users_from_own_branch_and_admin_sees_all(): void
    {
        $creator = $this->user();
        $ownBranch = $this->branch($creator, 'TRC-01');
        $otherBranch = $this->branch($creator, 'TRC-02');
        $manager = $this->user();
        $administrator = $this->user();
        $ownStaff = $this->user();
        $otherStaff = $this->user();
        $this->assignment($manager, $creator, 'branch_manager', $ownBranch);
        $this->assignment($administrator, $creator, 'admin');
        $this->assignment($ownStaff, $creator, 'cashier', $ownBranch);
        $this->assignment($otherStaff, $creator, 'cashier', $otherBranch);

        Sanctum::actingAs($manager);
        $managerResponse = $this->getJson('/api/v1/users')->assertOk();
        self::assertEqualsCanonicalizing(
            [$manager->id, $ownStaff->id],
            array_column($managerResponse->json('data'), 'id'),
        );
        $this->getJson("/api/v1/users?branch_id={$otherBranch->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_SCOPE_DENIED');

        Sanctum::actingAs($administrator);
        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonCount(5, 'data');
    }

    private function user(): User
    {
        $email = Str::uuid()->toString().'@example.test';

        return User::factory()->create([
            'email' => $email,
            'normalized_email' => $email,
            'state' => 'ACTIVE',
        ]);
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

    private function assignment(User $user, User $actor, string $roleCode, ?BranchRecord $branch = null): void
    {
        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => Role::query()->where('code', $roleCode)->value('id'),
            'branch_id' => $branch?->id,
            'scope_type' => $branch === null ? 'GLOBAL' : 'BRANCH',
            'status' => 'ACTIVE',
            'assigned_by_user_id' => $actor->id,
            'assigned_at' => now()->subDay(),
        ]);
    }
}
