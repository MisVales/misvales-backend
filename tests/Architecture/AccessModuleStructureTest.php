<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccessModuleStructureTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function moduleDirectories(): array
    {
        $areas = ['Accounts', 'Authentication', 'Authorization', 'MFA', 'Sessions', 'Security'];
        $directories = [];

        foreach (['Application', 'Domain'] as $layer) {
            foreach ($areas as $area) {
                $path = "app/Modules/Access/{$layer}/{$area}";
                $directories[$path] = [$path];
            }
        }

        foreach (['Persistence', 'Redis', 'WebAuthn', 'Notifications', 'Audit'] as $area) {
            $path = "app/Modules/Access/Infrastructure/{$area}";
            $directories[$path] = [$path];
        }

        $directories['app/Modules/Access/Presentation/Http'] = ['app/Modules/Access/Presentation/Http'];

        return $directories;
    }

    #[DataProvider('moduleDirectories')]
    public function test_required_access_module_directory_exists(string $path): void
    {
        self::assertDirectoryExists(dirname(__DIR__, 2).'/'.$path);
    }
}
