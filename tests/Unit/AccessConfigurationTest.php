<?php

namespace Tests\Unit;

use Tests\TestCase;

final class AccessConfigurationTest extends TestCase
{
    public function test_m01_security_durations_have_the_required_defaults(): void
    {
        self::assertSame('UTC', config('app.timezone'));
        self::assertSame('America/Monterrey', config('access.display_timezone'));
        self::assertSame(10, config('access.tokens.access_ttl_minutes'));
        self::assertSame(480, config('access.tokens.admin_refresh_ttl_minutes'));
        self::assertSame(480, config('access.tokens.tablet_refresh_ttl_minutes'));
        self::assertSame(1440, config('access.tokens.distributor_refresh_ttl_minutes'));
        self::assertSame(15, config('access.tokens.password_recovery_ttl_minutes'));
        self::assertSame(1440, config('access.tokens.invitation_ttl_minutes'));
        self::assertSame(3, config('access.sessions.max_active'));
        self::assertSame(15, config('access.sessions.admin_idle_timeout_minutes'));
        self::assertSame(15, config('access.sessions.tablet_idle_timeout_minutes'));
        self::assertSame(30, config('access.sessions.distributor_idle_timeout_minutes'));
        self::assertSame(5, config('access.sessions.capacity_challenge_ttl_minutes'));
        self::assertSame(5, config('access.challenges.authentication_ttl_minutes'));
        self::assertSame(5, config('access.challenges.webauthn_ttl_minutes'));
        self::assertSame(5, config('access.tokens.reauthorization_ttl_minutes'));
        self::assertSame(5, config('access.tokens.operational_ttl_minutes'));
        self::assertSame(10, config('access.security.recovery_code_count'));
        self::assertSame(5, config('access.security.password_history_count'));
    }
}
