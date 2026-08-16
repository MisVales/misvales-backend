<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Testing\LocalTestingUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class DatabaseSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_builds_the_foundation_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $firstCounts = $this->foundationCounts();

        self::assertSame(7, $firstCounts['roles']);
        self::assertSame(6, $firstCounts['users']);
        self::assertSame(6, $firstCounts['user_role_scopes']);
        self::assertSame(6, $firstCounts['mfa_credentials']);
        self::assertSame(1, $firstCounts['branches']);
        self::assertSame(16, $firstCounts['configuration_definitions']);
        self::assertSame(14, $firstCounts['configuration_versions']);
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
            'address' => 'Torreón, Coahuila',
            'status' => 'ACTIVE',
            'is_headquarters' => true,
        ]);
        self::assertSame(1, DB::table('branches')->where('is_headquarters', true)->count());

        foreach ([
            'test@gmail.com' => ['general_manager', 'GLOBAL'],
            'qa.gs.torreon@misvales.test' => ['branch_manager', 'BRANCH'],
            'qa.coord.a@misvales.test' => ['coordinator', 'BRANCH'],
            'qa.verificador@misvales.test' => ['verifier', 'BRANCH'],
            'qa.cajera@misvales.test' => ['cashier', 'BRANCH'],
            'qa.admin@misvales.test' => ['admin', 'GLOBAL'],
        ] as $email => [$roleCode, $scopeType]) {
            self::assertSame(1, DB::table('users as user')
                ->join('user_role_scopes as scope', 'scope.user_id', '=', 'user.id')
                ->join('roles as role', 'role.id', '=', 'scope.role_id')
                ->where('user.normalized_email', $email)
                ->where('role.code', $roleCode)
                ->where('scope.scope_type', $scopeType)
                ->where('scope.status', 'ACTIVE')
                ->whereNull('scope.revoked_at')
                ->count(), $email);
        }

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

        foreach (['categories', 'category_versions', 'products', 'product_versions', 'redemption_periods', 'distributors', 'clients', 'vouchers'] as $table) {
            self::assertSame(0, DB::table($table)->count(), $table);
        }

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

    public function test_local_testing_users_seeder_refuses_non_local_environments(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        try {
            $this->expectException(RuntimeException::class);
            (new LocalTestingUsersSeeder)->run();
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
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
            'CREDIT_TOLERANCE_AMOUNT' => '500.0000',
            'LATE_FEE_AMOUNT' => '300.0000',
            'POINTS_DIVISOR_AMOUNT' => '1200.0000',
            'POINTS_MULTIPLIER' => 3,
            'POINT_VALUE_AMOUNT' => '2.0000',
            'LATE_POINTS_REDUCTION_RATE' => '0.2000',
            'MODIFICATION_TOKEN_TTL' => 5,
        ];
    }
}
