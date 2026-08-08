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

final class BranchPersonnelApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_general_manager_lists_active_branch_personnel_and_applies_filters(): void
    {
        $manager = $this->user('Gerente general');
        $branch = $this->branch($manager, 'TRC-01');
        $cashier = $this->role('cashier');
        $coordinator = $this->role('coordinator');
        $activeCashier = $this->user('Cajera activa');
        $blockedCashier = $this->user('Cajera bloqueada', 'BLOCKED');
        $formerCoordinator = $this->user('Coordinador anterior');

        $this->assignment($manager, $manager, $this->role('general_manager'));
        $this->assignment($activeCashier, $manager, $cashier, $branch);
        $this->assignment($blockedCashier, $manager, $cashier, $branch);
        $this->assignment($formerCoordinator, $manager, $coordinator, $branch, active: false);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/branches/{$branch->id}/personnel")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.assignment_status', 'ACTIVE');

        $this->getJson("/api/v1/branches/{$branch->id}/personnel?role_id={$cashier->id}&state=BLOCKED")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.id', $blockedCashier->id)
            ->assertJsonPath('data.0.role.code', 'cashier')
            ->assertJsonPath('data.0.branch_id', $branch->id);
    }

    public function test_branch_manager_can_only_list_personnel_from_own_branch(): void
    {
        $creator = $this->user('Creador');
        $manager = $this->user('Gerente de sucursal');
        $ownBranch = $this->branch($creator, 'TRC-01');
        $otherBranch = $this->branch($creator, 'TRC-02');
        $this->assignment($manager, $creator, $this->role('branch_manager'), $ownBranch);
        Sanctum::actingAs($manager);

        $this->getJson("/api/v1/branches/{$ownBranch->id}/personnel")
            ->assertOk();

        $this->getJson("/api/v1/branches/{$otherBranch->id}/personnel")
            ->assertForbidden()
            ->assertJsonPath('code', 'ORGANIZATION_SCOPE_DENIED');
    }

    public function test_administrator_can_consult_personnel_in_read_only_global_mode(): void
    {
        $creator = $this->user('Creador');
        $administrator = $this->user('Administrador');
        $branch = $this->branch($creator, 'TRC-01');
        $this->assignment($administrator, $creator, $this->role('admin'));
        $this->assignment($this->user('Personal'), $creator, $this->role('verifier'), $branch);
        Sanctum::actingAs($administrator);

        $this->getJson("/api/v1/branches/{$branch->id}/personnel")
            ->assertOk()
            ->assertJsonCount(1, 'data');
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
            'assignment_reason' => 'Cobertura operativa',
            'revoked_by_user_id' => $active ? null : $actor->id,
            'revoked_at' => $active ? null : now(),
            'revocation_reason' => $active ? null : 'Cambio de personal',
        ]);
    }
}
