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

        if (str_contains((string) $required['trusted_proxies'], '*')) {
            throw new RuntimeException('TRUSTED_PROXIES must list explicit proxy IPs or CIDRs in production.');
        }

        $turnstile = config('production.turnstile', []);
        if ((bool) ($turnstile['enabled'] ?? false)
            && (trim((string) ($turnstile['site_key'] ?? '')) === '' || trim((string) ($turnstile['secret'] ?? '')) === '')) {
            throw new RuntimeException('TURNSTILE_SITE_KEY and TURNSTILE_SECRET are required in production.');
        }

        $expected = [
            'database.default' => 'mysql',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'database.redis.client' => 'phpredis',
            'session.driver' => 'redis',
            'filesystems.default' => 's3',
        ];

        foreach ($expected as $key => $value) {
            if (config($key) !== $value) {
                throw new RuntimeException("Production configuration {$key} must be {$value}.");
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
}
