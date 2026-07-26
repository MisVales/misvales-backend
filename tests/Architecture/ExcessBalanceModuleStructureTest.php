<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ExcessBalanceModuleStructureTest extends TestCase
{
    public function test_excess_balance_module_keeps_layered_boundaries(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/ExcessBalance';

        foreach (['Application', 'Domain', 'Infrastructure', 'Presentation'] as $layer) {
            self::assertDirectoryExists($root.'/'.$layer);
        }
        self::assertFileExists($root.'/Application/Contracts/DetectedExcessRegistrar.php');
        self::assertFileExists($root.'/Application/Contracts/CreditBalanceApplicationPort.php');
        self::assertFileExists($root.'/Domain/Services/ExcessBalanceInvariant.php');
        self::assertFileExists($root.'/Presentation/Http/routes.php');
    }
}
