<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class PointsModuleStructureTest extends TestCase
{
    public function test_points_module_keeps_required_layers_and_fail_closed_boundaries(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Points';

        foreach (['Presentation', 'Application', 'Domain', 'Infrastructure'] as $layer) {
            self::assertDirectoryExists($root.'/'.$layer);
        }

        self::assertFileExists($root.'/Application/Contracts/RelationPointSource.php');
        self::assertFileExists($root.'/Infrastructure/Integrations/UnavailableRelationPointSource.php');
        self::assertFileExists($root.'/Application/Services/RequestPointRedemption.php');
        self::assertFileExists($root.'/Application/Services/CompletePointRedemption.php');
    }
}
