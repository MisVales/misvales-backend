<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class ReportingModuleStructureTest extends TestCase
{
    public function test_reporting_layers_and_required_artifacts_exist(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Reporting';
        foreach (['Application', 'Domain', 'Infrastructure', 'Presentation'] as $layer) {
            self::assertDirectoryExists($root.'/'.$layer);
        }
        self::assertFileExists(dirname(__DIR__, 2).'/config/reporting.php');
        self::assertFileExists(dirname(__DIR__, 2).'/database/migrations/2026_07_26_960000_create_reporting_module_tables.php');
        self::assertFileExists($root.'/README.md');
    }
}
