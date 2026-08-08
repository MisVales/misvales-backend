<?php

namespace Tests\Unit\Services\Auth;

use App\Http\Controllers\Api\V1\MeController;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules;
use App\Services\Auth\RoleAssignmentPolicyService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RoleAssignmentPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_rejects_a_global_branch_manager_assignment(): void
    {
        $service = new RoleAssignmentPolicyService(new OrganizationAssignmentRules);
        $actor = new User(['state' => 'ACTIVE']);
        $target = new User(['state' => 'ACTIVE']);
        $role = new Role(['code' => 'branch_manager']);

        $result = $service->validateAssignment($actor, $target, $role, null);

        $this->assertSame(
            'El rol branch_manager no admite el alcance GLOBAL.',
            $result,
        );
    }

    public function test_a_malformed_global_branch_manager_scope_grants_no_branch_jurisdiction(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', 'branch_manager')->firstOrFail();

        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => null,
            'scope_type' => 'GLOBAL',
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        $this->assertFalse($user->hasScopeForBranch(Str::uuid()->toString()));
    }

    public function test_me_excludes_permissions_from_a_malformed_role_scope(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['state' => 'ACTIVE']);
        $role = Role::query()->where('code', 'branch_manager')->firstOrFail();

        UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => null,
            'scope_type' => 'GLOBAL',
            'assigned_by_user_id' => $user->id,
            'assigned_at' => now(),
            'status' => 'ACTIVE',
        ]);

        $request = Request::create('/api/v1/me');
        $request->setUserResolver(fn () => $user);
        $response = (new MeController)->show($request, new OrganizationAssignmentRules);

        $this->assertSame([], $response->getData(true)['scopes']);
        $this->assertSame([], $response->getData(true)['effective_permissions']);
    }
}
