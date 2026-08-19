<?php

namespace Tests\Feature\Credito;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Branch;
use App\Models\Distribuidora;
use App\Models\LineaCredito;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LineaCreditoConsultaScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);
    }

    public function test_branch_manager_lists_and_reads_only_credit_lines_from_own_branch(): void
    {
        $ownBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $manager = $this->userWithRole('branch_manager', $ownBranch->id);

        $ownDistributor = $this->distributorWithLine($ownBranch);
        $otherDistributor = $this->distributorWithLine($otherBranch);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/credit-lines')
            ->assertSuccessful()
            ->assertJsonPath('data.0.id', $ownDistributor['line']->id)
            ->assertJsonMissing(['id' => $otherDistributor['line']->id]);

        $this->getJson("/api/v1/distributors/{$ownDistributor['distributor']->id}/credit-line")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $ownDistributor['line']->id)
            ->assertJsonPath('data.capabilities.can_view_movements', true);
        $this->getJson("/api/v1/distributors/{$otherDistributor['distributor']->id}/credit-line")
            ->assertNotFound();
    }

    /** @return array{distributor: Distribuidora, line: LineaCredito} */
    private function distributorWithLine(Branch $branch): array
    {
        $user = $this->userWithRole('distributor', $branch->id);
        $distributor = Distribuidora::factory()->active()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        return [
            'distributor' => $distributor,
            'line' => LineaCredito::factory()->create(['distributor_id' => $distributor->id]),
        ];
    }

    private function userWithRole(string $roleCode, ?string $branchId = null): User
    {
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', $roleCode)->firstOrFail();
        UserRoleScope::query()->create([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'assigned_by_user_id' => $user->id,
        ]);

        return $user;
    }
}
