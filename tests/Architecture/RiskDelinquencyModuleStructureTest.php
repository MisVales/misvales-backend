<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class RiskDelinquencyModuleStructureTest extends TestCase
{
    public function test_module_preserves_layers_and_fail_closed_financial_boundaries(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/RiskDelinquency';
        foreach (['Presentation', 'Application', 'Domain', 'Infrastructure'] as $layer) {
            self::assertDirectoryExists($root.'/'.$layer);
        }
        self::assertFileExists($root.'/Application/Contracts/RelationRiskSourcePort.php');
        self::assertFileExists($root.'/Application/Contracts/OverdueBalancePort.php');
        self::assertFileExists($root.'/Application/Contracts/CanDistributorIssueVoucher.php');
        self::assertFileExists($root.'/Infrastructure/Integrations/UnavailableRelationRiskSource.php');
        self::assertFileExists($root.'/Infrastructure/Integrations/UnavailableOverdueBalance.php');
        self::assertFileExists($root.'/Presentation/Http/Policies/DistributorRiskProfilePolicy.php');
        self::assertFileExists($root.'/Presentation/Http/Policies/RiskAlertPolicy.php');
        self::assertFileExists($root.'/Presentation/Http/Policies/DelinquencyRemovalRequestPolicy.php');
    }

    public function test_forbidden_generic_state_routes_are_absent(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/app/Modules/RiskDelinquency/Presentation/Http/routes.php');
        self::assertIsString($routes);
        self::assertStringNotContainsString('Route::patch', $routes);
        self::assertStringNotContainsString('Route::delete', $routes);
    }
}
