<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    public function test_repository_does_not_ship_the_fixed_credential_bootstrap(): void
    {
        self::assertFileDoesNotExist(base_path('create_coordinator.php'));
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
        config()->set('filesystems.disks.private', [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
        ]);
    }
}
