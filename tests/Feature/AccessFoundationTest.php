<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Database\Seeders\AccessFoundationSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogs_are_idempotent(): void
    {
        $this->seed(AccessFoundationSeeder::class);
        $this->seed(AccessFoundationSeeder::class);

        $this->assertDatabaseCount('roles', count(RoleCode::cases()));
        $this->assertDatabaseCount('permissions', count(PermissionCode::cases()));
        $this->assertSame(1, Branch::query()->where('is_headquarters', true)->count());
    }

    public function test_a_users_single_role_is_immutable(): void
    {
        $this->requirePostgreSql();
        $this->seed(AccessFoundationSeeder::class);
        $user = User::factory()->generalManager()->create();
        $replacement = Role::query()->where('code', RoleCode::ADMINISTRATOR->value)->firstOrFail();

        $this->expectException(QueryException::class);
        $user->forceFill(['role_id' => $replacement->id])->save();
    }

    public function test_branch_role_without_branch_is_rejected(): void
    {
        $this->requirePostgreSql();
        $this->seed(AccessFoundationSeeder::class);

        $this->expectException(QueryException::class);
        User::factory()->sucursalManager()->create(['branch_id' => null]);
    }

    public function test_global_role_with_branch_is_rejected(): void
    {
        $this->requirePostgreSql();
        $this->seed(AccessFoundationSeeder::class);

        $this->expectException(QueryException::class);
        User::factory()->generalManager()->create(['branch_id' => Branch::factory()]);
    }

    public function test_branch_role_with_inactive_branch_is_rejected(): void
    {
        $this->requirePostgreSql();
        $this->seed(AccessFoundationSeeder::class);
        $branch = Branch::factory()->create(['is_active' => false]);

        $this->expectException(QueryException::class);
        User::factory()->cashier()->create(['branch_id' => $branch->id]);
    }

    public function test_administrator_has_no_write_permissions(): void
    {
        $this->seed(AccessFoundationSeeder::class);
        $role = Role::query()->where('code', RoleCode::ADMINISTRATOR->value)->firstOrFail();
        $permissions = $role->permissions()->pluck('code')->map(fn (PermissionCode $code) => $code->value);

        $this->assertTrue($permissions->every(fn (string $code) => ! str_starts_with($code, 'accounts.')));
        $this->assertContains(PermissionCode::SECURITY_AUDIT_GLOBAL_READ->value, $permissions);
    }

    public function test_factories_create_a_valid_context_for_every_role(): void
    {
        $this->seed(AccessFoundationSeeder::class);
        $states = ['generalManager', 'sucursalManager', 'coordinator', 'verifier', 'administrator', 'distributor', 'cashier'];

        foreach ($states as $state) {
            $user = User::factory()->{$state}()->create();
            $user->load('role', 'branch');

            $this->assertSame($user->role->code->isGlobal(), $user->branch === null);
            if ($user->branch !== null) {
                $this->assertTrue($user->branch->is_active);
            }
        }
    }

    public function test_role_and_permission_codes_match_the_frozen_catalog(): void
    {
        $this->seed(AccessFoundationSeeder::class);

        $roles = [
            'GENERAL_MANAGER', 'SUCURSAL_MANAGER', 'COORDINATOR', 'VERIFIER',
            'ADMINISTRATOR', 'DISTRIBUTOR', 'CASHIER',
        ];
        $permissions = [
            'auth.context.read', 'auth.sessions.read_own', 'auth.sessions.revoke_own',
            'auth.password.change_own', 'auth.mfa.manage_own', 'accounts.global.create',
            'accounts.branch.request', 'accounts.global.approve', 'accounts.global.disable',
            'accounts.branch.disable_request', 'security.alerts.global.read',
            'security.alerts.branch.read', 'security.audit.global.read',
            'onboarding.applications.create', 'onboarding.applications.update_capture',
            'onboarding.applications.submit', 'onboarding.applications.view_assigned',
            'onboarding.applications.view_branch', 'onboarding.applications.view_global',
            'onboarding.applications.review', 'onboarding.verifications.assign',
            'onboarding.verifications.perform', 'onboarding.applications.correct',
            'onboarding.applications.evaluate', 'onboarding.applications.authorize_branch',
            'onboarding.applications.authorize_global', 'onboarding.evidence.view',
            'onboarding.history.view',
            'clients.view.global', 'clients.view.branch', 'clients.view.assigned',
            'clients.create.own', 'clients.view_sensitive.authorized',
            'clients.view_documents.authorized', 'clients.apply_authorized_change',
            'clients.portfolio.view.own', 'clients.portfolio.write.own',
            'clients.assignment.apply_internal',
            'configuration.view.current', 'configuration.view.history',
            'configuration.manage', 'configuration.publish',
            'configuration.category.view', 'configuration.category.manage',
            'configuration.category.publish', 'configuration.product.view',
            'configuration.product.manage', 'configuration.product.publish',
            'configuration.redemption_period.view', 'configuration.redemption_period.manage',
            'vouchers.view', 'vouchers.open_at_counter', 'vouchers.release',
            'vouchers.reject', 'vouchers.fulfill', 'voucher_modifications.request',
            'voucher_modifications.apply', 'voucher_modifications.view',
            'voucher_modifications.decide',
            'payments.view.own', 'payments.view.branch', 'payments.view.assigned',
            'payments.view.global', 'bank_imports.upload', 'bank_imports.retry.branch',
            'bank_imports.retry.global', 'clarifications.create.own',
            'clarifications.review.branch', 'manual_reconciliations.request',
            'manual_reconciliations.authorize.assigned',
            'manual_reconciliations.authorize.branch',
            'manual_reconciliations.authorize.global', 'manual_reconciliations.apply',
            'excess_balances.decide.own', 'excess_balances.view.own',
            'excess_balances.view.branch', 'excess_balances.view.assigned',
            'excess_balances.view.global', 'refunds.view.own',
            'refunds.view.branch', 'refunds.view.assigned', 'refunds.view.global',
            'refunds.authorize.branch', 'refunds.authorize.global',
            'refunds.complete', 'refunds.evidence.view', 'payment_evidence.view',
            'points.view.own', 'points.view.branch', 'points.view.assigned',
            'points.view.global', 'points.redemptions.decide.branch',
            'points.redemptions.decide.global', 'points.runs.view.global',
            'risk.view.own', 'risk.view.assigned', 'risk.view.branch', 'risk.view.global',
            'risk.block.view.branch', 'delinquency.apply.branch', 'delinquency.apply.global',
            'delinquency.removal.prepare', 'delinquency.removal.decide.branch',
            'delinquency.removal.decide.global',
            'mobility.view.own', 'mobility.view.assigned', 'mobility.view.branch',
            'mobility.view.global', 'mobility.transfer.create.own',
            'mobility.transfer.respond.own', 'mobility.transfer.authorize.assigned',
            'mobility.reassign.branch', 'mobility.reassign.global',
            'mobility.branch_change.branch', 'mobility.branch_change.global',
            'mobility.coordinator_reassign.branch', 'mobility.coordinator_reassign.global',
            'mobility.assignment.view.branch',
            'reports.view.own', 'reports.view.assigned', 'reports.view.branch',
            'reports.view.global',
        ];

        $this->assertEqualsCanonicalizing(
            $roles,
            Role::query()->pluck('code')->map(fn (RoleCode $code) => $code->value)->all(),
        );
        $this->assertEqualsCanonicalizing(
            $permissions,
            Permission::query()->pluck('code')->map(fn (PermissionCode $code) => $code->value)->all(),
        );

        $openApi = file_get_contents(base_path('docs/openapi.yaml'));
        foreach ([...$roles, ...$permissions] as $code) {
            $this->assertStringContainsString("- {$code}", $openApi);
        }
    }

    public function test_role_and_branch_are_not_mass_assignable(): void
    {
        $user = new User;

        $this->assertFalse($user->isFillable('role_id'));
        $this->assertFalse($user->isFillable('branch_id'));
    }

    public function test_reseeding_does_not_remove_permissions_owned_by_m02(): void
    {
        $this->seed(AccessFoundationSeeder::class);
        $roleId = Role::query()->where('code', RoleCode::GENERAL_MANAGER->value)->value('id');
        $permissionId = DB::table('permissions')->insertGetId([
            'code' => 'm02.branch.manage',
            'name' => 'Administrar sucursales',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);

        $this->seed(AccessFoundationSeeder::class);

        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
    }

    private function requirePostgreSql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only constraint.');
        }
    }
}
