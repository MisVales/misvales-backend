<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;
use App\Modules\DistributorOnboarding\Persistence\Models\VerifierAssignment;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DistributorOnboardingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessFoundationSeeder::class);
    }

    public function test_anonymous_access_is_rejected(): void
    {
        $this->getJson('/api/v1/distributor-applications')->assertUnauthorized();
    }

    public function test_administrator_has_global_read_only_access(): void
    {
        $administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($administrator);

        $this->getJson('/api/v1/distributor-applications')->assertOk();
        $this->postJson('/api/v1/distributor-applications', [
            'contact_email' => 'aspirante@example.test',
            'account_name' => 'Aspirante',
        ], ['Idempotency-Key' => 'admin-cannot-create'])->assertForbidden();
        $this->assertDatabaseHas('security_events', [
            'actor_user_id' => $administrator->id,
            'rule_code' => 'AUTH_SCOPE_DENIED',
            'scope' => 'M04',
            'result' => 'DENIED',
        ]);
    }

    public function test_cashier_has_no_m04_access(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->getJson('/api/v1/distributor-applications')->assertForbidden();
    }

    public function test_pending_capture_and_assignment_authorities_are_not_granted_to_any_role(): void
    {
        foreach (Role::query()->with('permissions')->get() as $role) {
            $codes = $role->permissions->pluck('code')->map(
                fn (PermissionCode $permission): string => $permission->value,
            );
            self::assertNotContains(PermissionCode::ONBOARDING_APPLICATIONS_CREATE->value, $codes, $role->code->value);
            self::assertNotContains(PermissionCode::ONBOARDING_APPLICATIONS_UPDATE_CAPTURE->value, $codes, $role->code->value);
            self::assertNotContains(PermissionCode::ONBOARDING_APPLICATIONS_SUBMIT->value, $codes, $role->code->value);
            self::assertNotContains(PermissionCode::ONBOARDING_VERIFICATIONS_ASSIGN->value, $codes, $role->code->value);
        }
    }

    public function test_coordinator_and_verifier_only_list_assigned_applications(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $coordinator = User::factory()->coordinator()->create(['branch_id' => $branch->id]);
        $otherCoordinator = User::factory()->coordinator()->create(['branch_id' => $branch->id]);
        $verifier = User::factory()->verifier()->create(['branch_id' => $branch->id]);
        $otherVerifier = User::factory()->verifier()->create(['branch_id' => $otherBranch->id]);
        $assigned = $this->application($branch, $coordinator, 'ASSIGNED');
        $this->application($branch, $otherCoordinator, 'OTHER-COORDINATOR');
        $this->application($otherBranch, $otherCoordinator, 'OTHER-BRANCH');

        $assignment = new VerifierAssignment;
        $assignment->forceFill([
            'application_id' => $assigned->id,
            'verifier_user_id' => $verifier->id,
            'branch_id' => $branch->id,
            'assigned_by' => $coordinator->id,
            'assigned_at' => now(),
            'active_slot' => true,
            'lock_version' => 1,
        ])->save();

        Sanctum::actingAs($coordinator);
        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', 'ASSIGNED');

        Sanctum::actingAs($verifier);
        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.folio', 'ASSIGNED');

        Sanctum::actingAs($otherVerifier);
        $this->getJson('/api/v1/distributor-applications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_branch_manager_is_scoped_and_general_manager_has_global_read_access(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $coordinator = User::factory()->coordinator()->create(['branch_id' => $branch->id]);
        $otherCoordinator = User::factory()->coordinator()->create(['branch_id' => $otherBranch->id]);
        $local = $this->application($branch, $coordinator, 'LOCAL');
        $remote = $this->application($otherBranch, $otherCoordinator, 'REMOTE');

        Sanctum::actingAs(User::factory()->sucursalManager()->create(['branch_id' => $branch->id]));
        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $local->public_id);
        $this->getJson('/api/v1/distributor-applications/'.$remote->public_id)->assertNotFound();

        Sanctum::actingAs(User::factory()->generalManager()->create());
        $this->getJson('/api/v1/distributor-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    private function application(Branch $branch, User $coordinator, string $folio): DistributorApplication
    {
        $application = new DistributorApplication;
        $application->forceFill([
            'folio' => $folio,
            'contact_email' => mb_strtolower($folio).'@example.test',
            'normalized_email_hash' => hash('sha256', $folio),
            'account_name' => 'Aspirante '.$folio,
            'branch_id' => $branch->id,
            'coordinator_user_id' => $coordinator->id,
            'status' => ApplicationStatus::CAPTURE,
            'lock_version' => 1,
            'created_by' => $coordinator->id,
        ])->save();

        return $application;
    }
}
