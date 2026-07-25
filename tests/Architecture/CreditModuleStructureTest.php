<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditModuleStructureTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function requiredDirectories(): array
    {
        $paths = [
            'Application/Commands',
            'Application/Queries',
            'Application/DTOs',
            'Application/Contracts',
            'Application/Services',
            'Domain/Aggregates',
            'Domain/ValueObjects',
            'Domain/Enums',
            'Domain/Rules',
            'Domain/Services',
            'Domain/Events',
            'Domain/Exceptions',
            'Domain/Repositories',
            'Infrastructure/Persistence/Eloquent/Models',
            'Infrastructure/Persistence/Eloquent/Repositories',
            'Infrastructure/Persistence/Eloquent/Mappers',
            'Infrastructure/Providers',
            'Presentation/Http/Controllers',
            'Presentation/Http/Requests',
            'Presentation/Http/Resources',
        ];

        return array_combine($paths, array_map(static fn (string $path): array => [$path], $paths));
    }

    #[DataProvider('requiredDirectories')]
    public function test_m07_uses_the_required_module_boundaries(string $path): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2).'/app/Modules/Credit/'.$path);
    }
}
