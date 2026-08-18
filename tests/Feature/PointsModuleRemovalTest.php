<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Reportes\ServicioReportes;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PointsModuleRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_module_cannot_reappear_in_runtime_catalogs_or_schema(): void
    {
        $this->seed(DatabaseSeeder::class);

        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => mb_strtolower($route->uri()));

        self::assertFalse($uris->contains(fn (string $uri): bool => str_contains($uri, 'point')));
        self::assertFalse($uris->contains(fn (string $uri): bool => str_contains($uri, 'redemption')));
        self::assertNotContains('points', ServicioReportes::REPORTS);
        self::assertNotContains('point-movements', ServicioReportes::REPORTS);
        self::assertNull(config('points'));

        self::assertSame(0, DB::table('permissions')
            ->where('module', 'points')
            ->orWhere('code', 'like', 'points.%')
            ->count());
        self::assertSame(0, DB::table('configuration_definitions')
            ->whereIn('key', [
                'POINTS_DIVISOR_AMOUNT',
                'POINTS_MULTIPLIER',
                'POINT_VALUE_AMOUNT',
                'LATE_POINTS_REDUCTION_RATE',
            ])
            ->count());

        foreach (['point_accounts', 'point_movements', 'point_redemption_requests', 'redemption_periods'] as $table) {
            self::assertFalse(Schema::hasTable($table), $table);
        }

        $openApi = mb_strtolower((string) file_get_contents(base_path('docs/openapi.yml')));
        self::assertStringNotContainsString('/me/points', $openApi);
        self::assertStringNotContainsString('/point-redemption-requests', $openApi);
    }
}
