<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DistributorOnboardingModuleStructureTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function directories(): iterable
    {
        foreach ([
            'Application/Applications',
            'Application/Reviews',
            'Application/VerificationAssignments',
            'Application/Visits',
            'Application/Corrections',
            'Application/Evaluations',
            'Application/Authorizations',
            'Application/Activation',
            'Domain/Applications',
            'Domain/Expedients',
            'Domain/Verification',
            'Domain/Decisions',
            'Domain/Contracts',
            'Persistence',
            'Presentation/Http',
        ] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('directories')]
    public function test_required_module_directory_exists(string $path): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2).'/app/Modules/DistributorOnboarding/'.$path);
    }
}
