<?php

declare(strict_types=1);

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

final class ProductionConfigurationCommandTest extends TestCase
{
    public function test_release_validator_rejects_debug_and_local_auth_bypasses(): void
    {
        $this->configureSafeProductionBaseline();
        config()->set('app.debug', true);
        config()->set('bootstrap.local_super_session.enabled', true);
        config()->set('bootstrap.local_testing_totp_secret', 'synthetic-secret');

        $this->artisan('app:validate-production')
            ->expectsOutputToContain('APP_DEBUG_ENABLED')
            ->expectsOutputToContain('LOCAL_SUPER_SESSION_ENABLED')
            ->expectsOutputToContain('LOCAL_TESTING_TOTP_SECRET_CONFIGURED')
            ->assertFailed();
    }

    public function test_release_validator_accepts_the_minimum_safe_configuration(): void
    {
        $this->configureSafeProductionBaseline();

        $this->artisan('app:validate-production')
            ->expectsOutputToContain('Production configuration is safe.')
            ->assertSuccessful();
    }

    public function test_release_validator_rejects_disabled_rate_limiting(): void
    {
        $this->configureSafeProductionBaseline();
        config()->set('ratelimit.enabled', false);

        $this->artisan('app:validate-production')
            ->expectsOutputToContain('RATE_LIMIT_DISABLED')
            ->assertFailed();
    }

    public function test_release_validator_rejects_frontend_missing_from_cors_and_sanctum(): void
    {
        $this->configureSafeProductionBaseline();
        config()->set('cors.allowed_origins', ['https://otro.example']);
        config()->set('sanctum.stateful', ['otro.example']);

        $this->artisan('app:validate-production')
            ->expectsOutputToContain('FRONTEND_ORIGIN_NOT_ALLOWED')
            ->expectsOutputToContain('FRONTEND_HOST_NOT_STATEFUL')
            ->assertFailed();
    }

    public function test_repository_does_not_ship_the_fixed_credential_bootstrap(): void
    {
        self::assertFileDoesNotExist(base_path('create_coordinator.php'));
    }

    public function test_mysql_read_and_write_connections_timeout_after_three_seconds(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('pdo_mysql is required to inspect the connection timeout.');
        }

        self::assertSame(3, config('database.connections.mysql.read.options')[PDO::ATTR_TIMEOUT]);
        self::assertSame(3, config('database.connections.mysql.write.options')[PDO::ATTR_TIMEOUT]);
    }

    private function configureSafeProductionBaseline(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.key', 'base64:synthetic-test-key');
        config()->set('app.url', 'https://misvales.example');
        config()->set('bootstrap.local_super_session.enabled', false);
        config()->set('bootstrap.local_testing_totp_secret');
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('cors.supports_credentials', true);
        config()->set('cors.allowed_origins', ['https://app.misvales.example']);
        config()->set('cors.allowed_origins_patterns', []);
        config()->set('production.frontend_url', 'https://app.misvales.example');
        config()->set('sanctum.stateful', ['app.misvales.example']);
        config()->set('filesystems.disks.private', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
        ]);
    }
}
