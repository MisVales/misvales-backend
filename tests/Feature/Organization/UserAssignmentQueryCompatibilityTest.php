<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\RequireMfaCompleted;
use App\Http\Middleware\TrackSessionActivity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserAssignmentQueryCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $target;

    private UserRoleScope $activeAssignment;

    private UserRoleScope $historicalAssignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withoutMiddleware([TrackSessionActivity::class, RequireMfaCompleted::class]);

        $this->actor = $this->user();
        $this->target = $this->user();
        $role = Role::query()->where('code', 'general_manager')->firstOrFail();

        $this->assignment($this->actor, $role);
        $this->historicalAssignment = $this->assignment($this->target, $role, ended: true);
        $this->activeAssignment = $this->assignment($this->target, $role);

        Sanctum::actingAs($this->actor);
    }

    public function test_default_contract_still_returns_only_active_assignments(): void
    {
        $response = $this->getJson("/api/v1/users/{$this->target->id}/assignments");

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $this->activeAssignment->id)
            ->assertJsonPath('0.revoked_at', null)
            ->assertJsonPath('0.role.code', 'general_manager');
    }

    public function test_history_is_opt_in_through_query_parameter(): void
    {
        $response = $this->getJson(
            "/api/v1/users/{$this->target->id}/assignments?include_history=true",
        );

        $response->assertOk()->assertJsonCount(2);

        self::assertEqualsCanonicalizing([
            $this->activeAssignment->id,
            $this->historicalAssignment->id,
        ], array_column($response->json(), 'id'));
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

    private function assignment(User $user, Role $role, bool $ended = false): UserRoleScope
    {
        return UserRoleScope::query()->create([
            'id' => Str::uuid()->toString(),
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => null,
            'scope_type' => 'GLOBAL',
            'status' => $ended ? 'ENDED' : 'ACTIVE',
            'assigned_by_user_id' => $this->actor?->id ?? $user->id,
            'assigned_at' => $ended ? now()->subDays(2) : now(),
            'revoked_by_user_id' => $ended ? ($this->actor?->id ?? $user->id) : null,
            'revoked_at' => $ended ? now()->subDay() : null,
            'revocation_reason' => $ended ? 'Asignación histórica' : null,
        ]);
    }
}
