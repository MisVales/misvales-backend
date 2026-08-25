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
        if (! $this->frontendOriginIsAllowed()) {
            $violations[] = 'FRONTEND_ORIGIN_NOT_ALLOWED';
        }
        if (! $this->frontendHostIsStateful()) {
            $violations[] = 'FRONTEND_HOST_NOT_STATEFUL';
        }
        if (! (bool) config('ratelimit.enabled')) {
            $violations[] = 'RATE_LIMIT_DISABLED';
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

    private function frontendOriginIsAllowed(): bool
    {
        $frontendOrigin = rtrim((string) config('production.frontend_url'), '/');
        $origins = config('cors.allowed_origins', []);

        return $frontendOrigin !== ''
            && is_array($origins)
            && in_array($frontendOrigin, $origins, true);
    }

    private function frontendHostIsStateful(): bool
    {
        $frontendHost = parse_url((string) config('production.frontend_url'), PHP_URL_HOST);
        $statefulDomains = config('sanctum.stateful', []);

        return is_string($frontendHost)
            && $frontendHost !== ''
            && is_array($statefulDomains)
            && in_array($frontendHost, $statefulDomains, true);
    }
}
