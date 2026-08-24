<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PointsModuleRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_module_is_exposed_by_the_current_runtime_and_schema(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => mb_strtolower($route->uri()));

        self::assertTrue($uris->contains('api/v1/points/balance'));
        self::assertTrue($uris->contains('api/v1/points/redemptions'));

        foreach (['point_accounts', 'point_movements', 'point_redemption_requests'] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }

        $openApi = mb_strtolower((string) file_get_contents(base_path('docs/openapi.yml')));
        self::assertStringContainsString('/points/balance', $openApi);
        self::assertStringContainsString('/points/redemptions', $openApi);
    }
}
