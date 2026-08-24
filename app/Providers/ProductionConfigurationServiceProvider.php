<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class ProductionConfigurationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $required = config('production.required', []);
        $missing = array_keys(array_filter(
            $required,
            static fn (mixed $value): bool => trim((string) $value) === ''
        ));

        if ($missing !== []) {
            throw new RuntimeException('Missing required production configuration: '.implode(', ', $missing));
        }

        $expected = [
            'database.default' => 'mysql',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'database.redis.client' => 'phpredis',
            'session.driver' => 'redis',
            'filesystems.default' => 's3',
            'broadcasting.default' => 'reverb',
            'broadcasting.queue' => 'broadcasts',
        ];

        foreach ($expected as $key => $value) {
            if (config($key) !== $value) {
                throw new RuntimeException("Production configuration {$key} must be {$value}.");
            }
        }

        if ((bool) config('app.debug')) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }

        if (config('broadcasting.connections.reverb.options.scheme') !== 'https'
            || config('broadcasting.connections.reverb.options.useTLS') !== true) {
            throw new RuntimeException('Reverb broadcasting must use HTTPS/WSS in production.');
        }

        if (config('reverb.servers.reverb.scaling.enabled') !== true) {
            throw new RuntimeException('Reverb Redis scaling must be enabled in production.');
        }

        $reverbApp = config('reverb.apps.apps.0');
        if (! is_array($reverbApp)
            || ($reverbApp['accept_client_events_from'] ?? null) !== 'none'
            || ($reverbApp['rate_limiting']['enabled'] ?? false) !== true
            || $this->hasUnsafeRealtimeOrigins($reverbApp['allowed_origins'] ?? null)) {
            throw new RuntimeException('Reverb application security configuration is unsafe.');
        }

        foreach (['db_primary_host', 'db_replica_host', 'redis_host'] as $name) {
            $host = strtolower(trim((string) $required[$name]));
            if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException("{$name} must use a private VPC address in production.");
            }
        }

        foreach (config('database.connections.mysql.read.options', []) + config('database.connections.mysql.write.options', []) as $path) {
            if (is_bool($path)) {
                continue;
            }

            if (! is_file($path) || ! is_readable($path)) {
                throw new RuntimeException('Configured database TLS paths must reference readable files.');
            }
        }
    }

    private function hasUnsafeRealtimeOrigins(mixed $origins): bool
    {
        return ! is_array($origins)
            || $origins === []
            || collect($origins)->contains(
                static fn (mixed $origin): bool => ! is_string($origin)
                    || str_contains($origin, '*')
                    || str_contains($origin, '://')
                    || in_array(strtolower($origin), ['localhost', '127.0.0.1', '::1'], true)
            );
    }
}
