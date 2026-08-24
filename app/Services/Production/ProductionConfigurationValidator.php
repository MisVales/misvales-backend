<?php

declare(strict_types=1);

namespace App\Services\Production;

final class ProductionConfigurationValidator
{
    /** @return list<string> */
    public function violations(): array
    {
        $violations = [];

        if (config('app.env') !== 'production') {
            $violations[] = 'APP_ENV_NOT_PRODUCTION';
        }
        if ((bool) config('app.debug')) {
            $violations[] = 'APP_DEBUG_ENABLED';
        }
        if (! is_string(config('app.key')) || config('app.key') === '') {
            $violations[] = 'APP_KEY_MISSING';
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $violations[] = 'APP_URL_NOT_HTTPS';
        }
        if ((bool) config('bootstrap.local_super_session.enabled')) {
            $violations[] = 'LOCAL_SUPER_SESSION_ENABLED';
        }
        if (filled(config('bootstrap.local_testing_totp_secret'))) {
            $violations[] = 'LOCAL_TESTING_TOTP_SECRET_CONFIGURED';
        }
        if (config('session.secure') !== true) {
            $violations[] = 'SESSION_COOKIE_NOT_SECURE';
        }
        if (config('session.http_only') !== true) {
            $violations[] = 'SESSION_COOKIE_NOT_HTTP_ONLY';
        }
        if ((bool) config('cors.supports_credentials') && $this->hasWildcardOrigin()) {
            $violations[] = 'CORS_CREDENTIALS_WITH_WILDCARD';
        }
        if (! (bool) config('ratelimit.enabled')) {
            $violations[] = 'RATE_LIMIT_DISABLED';
        }
        if (config('broadcasting.default') !== 'reverb') {
            $violations[] = 'REVERB_BROADCASTING_DISABLED';
        }
        if (config('broadcasting.connections.reverb.options.scheme') !== 'https'
            || config('broadcasting.connections.reverb.options.useTLS') !== true) {
            $violations[] = 'REVERB_TLS_DISABLED';
        }
        if (config('reverb.servers.reverb.scaling.enabled') !== true) {
            $violations[] = 'REVERB_SCALING_DISABLED';
        }
        if (config('reverb.apps.apps.0.accept_client_events_from') !== 'none') {
            $violations[] = 'REVERB_CLIENT_EVENTS_ENABLED';
        }
        if (config('reverb.apps.apps.0.rate_limiting.enabled') !== true) {
            $violations[] = 'REVERB_RATE_LIMIT_DISABLED';
        }
        if ($this->hasUnsafeRealtimeOrigins()) {
            $violations[] = 'REVERB_ORIGINS_UNSAFE';
        }

        $privateDisk = config('filesystems.disks.private');
        if (! is_array($privateDisk)
            || ($privateDisk['serve'] ?? false) === true
            || ($privateDisk['visibility'] ?? null) === 'public') {
            $violations[] = 'PRIVATE_STORAGE_PUBLICLY_EXPOSED';
        }

        return $violations;
    }

    private function hasWildcardOrigin(): bool
    {
        $origins = config('cors.allowed_origins', []);
        $patterns = config('cors.allowed_origins_patterns', []);

        return ! is_array($origins)
            || ! is_array($patterns)
            || in_array('*', $origins, true)
            || in_array('*', $patterns, true);
    }

    private function hasUnsafeRealtimeOrigins(): bool
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

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
