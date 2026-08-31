<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDemoUsersSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DatabaseSeedersTest extends TestCase
{
    public function test_local_demo_seeder_creates_six_active_accounts_with_shared_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->seed(LocalDemoUsersSeeder::class);

        self::assertSame(6, DB::table('users')->count());
        foreach (DB::table('users')->pluck('password') as $password) {
            self::assertTrue(Hash::check('1234', $password));
        }
        $this->assertEqualsCanonicalizing([
            'administrador@gmail.com',
            'cajera@gmail.com',
            'coordinador@gmail.com',
            'gerentegeneral@gmail.com',
            'gerentesucursal@gmail.com',
            'verificador@gmail.com',
        ], DB::table('users')->pluck('normalized_email')->all());

        self::assertSame(5, DB::table('user_role_scopes as scope')
            ->join('users as user', 'user.id', '=', 'scope.user_id')
            ->join('branches as branch', 'branch.id', '=', 'scope.branch_id')
            ->where('branch.name', 'Sucursal Matamoros')
            ->where('scope.scope_type', 'BRANCH')
            ->where('scope.status', 'ACTIVE')
            ->whereNull('scope.revoked_at')
            ->whereIn('user.normalized_email', [
                'administrador@gmail.com',
                'cajera@gmail.com',
                'coordinador@gmail.com',
                'gerentesucursal@gmail.com',
                'verificador@gmail.com',
            ])
            ->count());
    }

    public function test_database_seeder_builds_the_foundation_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstCounts = $this->foundationCounts();

        self::assertSame(7, $firstCounts['roles']);
        self::assertSame(2, $firstCounts['users']);
        self::assertSame(2, $firstCounts['user_role_scopes']);
        self::assertSame(0, $firstCounts['mfa_credentials']);
        self::assertSame(2, $firstCounts['branches']);
        self::assertSame(20, $firstCounts['configuration_definitions']);
        self::assertSame(19, $firstCounts['configuration_versions']);
        self::assertGreaterThan(0, $firstCounts['estados']);
        self::assertGreaterThan(0, $firstCounts['municipios']);
        self::assertGreaterThan(0, $firstCounts['codigos_postales']);
        self::assertGreaterThan(0, $firstCounts['colonias']);

        $this->assertEqualsCanonicalizing([
            'admin', 'branch_manager', 'cashier', 'coordinator', 'distributor', 'general_manager', 'verifier',
        ], DB::table('roles')->pluck('code')->all());

        $this->assertDatabaseHas('branches', [
            'code' => 'MATRIZ',
            'name' => 'Sucursal Matriz',
            'address' => 'C. Pabellón 28, Centro, 27440 Matamoros, Coah.',
            'address_latitude' => 25.5280209,
            'address_longitude' => -103.2300172,
            'status' => 'ACTIVE',
            'is_headquarters' => true,
        ]);
        $this->assertDatabaseHas('branches', [
            'code' => 'MATAMOROS',
            'name' => 'Sucursal Matamoros',
            'status' => 'ACTIVE',
            'is_headquarters' => false,
        ]);
        self::assertSame(1, DB::table('branches')->where('is_headquarters', true)->count());

        $managerEmail = mb_strtolower((string) config('bootstrap.initial_general_manager.email'));
        self::assertSame(1, DB::table('users as user')
            ->join('user_role_scopes as scope', 'scope.user_id', '=', 'user.id')
            ->join('roles as role', 'role.id', '=', 'scope.role_id')
            ->where('user.normalized_email', $managerEmail)
            ->where('role.code', 'general_manager')
            ->where('scope.scope_type', 'GLOBAL')
            ->where('scope.status', 'ACTIVE')
            ->whereNull('scope.revoked_at')
            ->count(), $managerEmail);

        $adminEmail = mb_strtolower((string) config('bootstrap.initial_admin.email'));
        self::assertSame(1, DB::table('users as user')
            ->join('user_role_scopes as scope', 'scope.user_id', '=', 'user.id')
            ->join('roles as role', 'role.id', '=', 'scope.role_id')
            ->where('user.normalized_email', $adminEmail)
            ->where('role.code', 'admin')
            ->where('scope.scope_type', 'GLOBAL')
            ->where('scope.status', 'ACTIVE')
            ->whereNull('scope.revoked_at')
            ->count(), $adminEmail);

        $administratorSensitivePermissions = DB::table('role_permissions as role_permission')
            ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('role.code', 'admin')
            ->whereNull('role_permission.revoked_at')
            ->where('permission.is_sensitive', true)
            ->count();
        self::assertSame(0, $administratorSensitivePermissions);

        $this->assertEqualsCanonicalizing(
            ['distributor_applications.view', 'distributor_applications.view_sensitive'],
            DB::table('role_permissions as role_permission')
                ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
                ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                ->where('role.code', 'verifier')
                ->whereNull('role_permission.revoked_at')
                ->whereIn('permission.code', [
                    'distributor_applications.view',
                    'distributor_applications.view_sensitive',
                ])
                ->pluck('permission.code')
                ->all(),
        );

        self::assertSame(1, DB::table('role_permissions as role_permission')
            ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('role.code', 'coordinator')
            ->where('permission.code', 'relations.view_assigned')
            ->whereNull('role_permission.revoked_at')
            ->count());

        foreach ($this->expectedConfigurationValues() as $key => $expectedValue) {
            $storedValue = DB::table('configuration_versions as version')
                ->join('configuration_definitions as definition', 'definition.id', '=', 'version.configuration_definition_id')
                ->where('definition.key', $key)
                ->where('version.version', 1)
                ->where('version.status', 'PUBLISHED')
                ->whereNull('version.effective_to')
                ->value('version.value');

            self::assertNotNull($storedValue, $key);
            self::assertSame($expectedValue, json_decode((string) $storedValue, true), $key);
        }

        self::assertSame(3, DB::table('categories')->count());
        self::assertSame(3, DB::table('category_versions')->count());
        self::assertEqualsCanonicalizing([
            ['code' => 'CAT-COBRE', 'name' => 'Cobre', 'profit_percentage' => '0.030000'],
            ['code' => 'CAT-PLATA', 'name' => 'Plata', 'profit_percentage' => '0.060000'],
            ['code' => 'CAT-ORO', 'name' => 'Oro', 'profit_percentage' => '0.100000'],
        ], DB::table('categories as category')
            ->join('category_versions as version', 'version.category_id', '=', 'category.id')
            ->where('version.version', 1)
            ->select('category.code', 'version.name', 'version.profit_percentage')
            ->get()
            ->map(static fn ($category): array => [
                'code' => $category->code,
                'name' => $category->name,
                'profit_percentage' => $category->profit_percentage,
            ])
            ->all());
        self::assertSame(5, DB::table('products')->count());
        self::assertSame(5, DB::table('product_versions')->count());
        $seededProducts = DB::table('products as product')
            ->join('product_versions as version', 'version.product_id', '=', 'product.id')
            ->whereIn('product.code', ['VAL-1000', 'VAL-2000', 'VAL-3000', 'VAL-4000', 'VAL-5000'])
            ->select(
                'product.code',
                'product.status',
                'product.loan_commission_percentage',
                'product.simple_interest_percentage',
                'product.insurance_amount',
                'product.fortnights_count',
                'product.late_fee_amount',
                'version.name',
                'version.description',
                'version.nominal_amount',
                'version.loan_commission_percentage as version_commission',
                'version.simple_interest_percentage as version_interest',
                'version.insurance_amount as version_insurance',
                'version.fortnights_count as version_fortnights',
                'version.late_fee_amount as version_late_fee',
                'version.status as version_status',
            )
            ->orderBy('product.code')
            ->get();

        self::assertCount(5, $seededProducts);
        foreach ($seededProducts as $product) {
            self::assertSame('ACTIVE', $product->status, $product->code);
            self::assertSame('0.100000', $product->loan_commission_percentage, $product->code);
            self::assertSame('0.050000', $product->simple_interest_percentage, $product->code);
            self::assertSame('100.0000', $product->insurance_amount, $product->code);
            self::assertSame(8, (int) $product->fortnights_count, $product->code);
            self::assertSame('300.0000', $product->late_fee_amount, $product->code);
            self::assertSame('PUBLISHED', $product->version_status, $product->code);
            self::assertSame('0.100000', $product->version_commission, $product->code);
            self::assertSame('0.050000', $product->version_interest, $product->code);
            self::assertSame('100.0000', $product->version_insurance, $product->code);
            self::assertSame(8, (int) $product->version_fortnights, $product->code);
            self::assertSame('300.0000', $product->version_late_fee, $product->code);
        }

        self::assertSame(1, DB::table('role_permissions as role_permission')
            ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('role.code', 'admin')
            ->where('permission.code', 'delinquency_removal.decide_global')
            ->whereNull('role_permission.revoked_at')
            ->count());
        foreach (['distributors', 'clients', 'vouchers'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }

        foreach (['point_accounts', 'point_movements', 'point_redemption_requests'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }
        self::assertFalse(Schema::hasTable('redemption_periods'));
        self::assertGreaterThan(0, DB::table('permissions')->where('module', 'points')->orWhere('code', 'like', 'points.%')->count());
        self::assertSame(3, DB::table('configuration_definitions')->whereIn('key', [
            'POINTS_DIVISOR_AMOUNT',
            'POINTS_MULTIPLIER',
            'POINT_VALUE_AMOUNT',
        ])->count());
        self::assertSame(0, DB::table('configuration_definitions')->whereIn('key', [
            'LATE_POINTS_REDUCTION_RATE',
            'EARLY_PAYMENT_PERIOD',
        ])->count());

        self::assertSame(0, DB::table('municipios as municipality')
            ->leftJoin('estados as state', 'state.id', '=', 'municipality.estado_id')
            ->whereNull('state.id')->count());
        self::assertSame(0, DB::table('codigos_postales as postal_code')
            ->leftJoin('municipios as municipality', 'municipality.id', '=', 'postal_code.municipio_id')
            ->whereNull('municipality.id')->count());
        self::assertSame(0, DB::table('colonias as settlement')
            ->leftJoin('codigos_postales as postal_code', 'postal_code.id', '=', 'settlement.codigo_postal_id')
            ->whereNull('postal_code.id')->count());

        $this->seed(DatabaseSeeder::class);

        self::assertSame($firstCounts, $this->foundationCounts());
        self::assertSame(0, DB::table('role_permissions')
            ->select('role_id', 'permission_id')
            ->whereNull('revoked_at')
            ->groupBy('role_id', 'permission_id')
            ->havingRaw('COUNT(*) > 1')
            ->count());
    }

    /** @return array<string, int> */
    private function foundationCounts(): array
    {
        $counts = [];

        foreach (['roles', 'permissions', 'role_permissions', 'users', 'user_role_scopes', 'mfa_credentials', 'branches', 'configuration_definitions', 'configuration_versions', 'estados', 'municipios', 'codigos_postales', 'colonias'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return array<string, int|string> */
    private function expectedConfigurationValues(): array
    {
        return [
            'CUT_DAY_OF_MONTH' => 25,
            'PAYMENT_DAYS_AFTER_CUT' => 20,
            'BUSINESS_TIMEZONE' => 'America/Monterrey',
            'CUT_TIME' => '00:05',
            'PAYMENT_DEADLINE_TIME' => '23:59:59',
            'BANK_UPLOAD_DEADLINE_TIME' => '08:00',
            'POST_DUE_EVALUATION_TIME' => '08:30',
            'VERIFICATION_START_TIME' => '08:00',
            'VERIFICATION_MAX_START_TIME' => '23:45',
            'CREDIT_TOLERANCE_AMOUNT' => '500.0000',
            'POINTS_DIVISOR_AMOUNT' => '1200.0000',
            'POINTS_MULTIPLIER' => 3,
            'POINT_VALUE_AMOUNT' => '2.0000',
        ];
    }
}
