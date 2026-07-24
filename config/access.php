<?php

return [
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'America/Monterrey'),

    'tokens' => [
        'access_ttl_minutes' => (int) env('ACCESS_TOKEN_TTL_MINUTES', 10),
        'admin_refresh_ttl_minutes' => (int) env('ACCESS_ADMIN_REFRESH_TTL_MINUTES', 480),
        'tablet_refresh_ttl_minutes' => (int) env('ACCESS_TABLET_REFRESH_TTL_MINUTES', 480),
        'distributor_refresh_ttl_minutes' => (int) env('ACCESS_DISTRIBUTOR_REFRESH_TTL_MINUTES', 1440),
        'reauthorization_ttl_minutes' => (int) env('ACCESS_REAUTHORIZATION_TTL_MINUTES', 5),
        'operational_ttl_minutes' => (int) env('ACCESS_OPERATIONAL_TOKEN_TTL_MINUTES', 5),
        'password_recovery_ttl_minutes' => (int) env('ACCESS_PASSWORD_RECOVERY_TTL_MINUTES', 15),
        'invitation_ttl_minutes' => (int) env('ACCESS_INVITATION_TTL_MINUTES', 1440),
    ],

    'sessions' => [
        'max_active' => (int) env('ACCESS_MAX_ACTIVE_SESSIONS', 3),
        'admin_idle_timeout_minutes' => (int) env('ACCESS_ADMIN_IDLE_TIMEOUT_MINUTES', 15),
        'tablet_idle_timeout_minutes' => (int) env('ACCESS_TABLET_IDLE_TIMEOUT_MINUTES', 15),
        'distributor_idle_timeout_minutes' => (int) env('ACCESS_DISTRIBUTOR_IDLE_TIMEOUT_MINUTES', 30),
        'capacity_challenge_ttl_minutes' => (int) env('ACCESS_SESSION_CAPACITY_TTL_MINUTES', 5),
    ],

    'challenges' => [
        'authentication_ttl_minutes' => (int) env('ACCESS_AUTH_TRANSACTION_TTL_MINUTES', 5),
        'webauthn_ttl_minutes' => (int) env('ACCESS_WEBAUTHN_CHALLENGE_TTL_MINUTES', 5),
    ],

    'security' => [
        'recovery_code_count' => (int) env('ACCESS_RECOVERY_CODE_COUNT', 10),
        'password_history_count' => (int) env('ACCESS_PASSWORD_HISTORY_COUNT', 5),
    ],

    'webauthn' => [
        'rp_id' => env('WEBAUTHN_RP_ID', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        'origin' => env('WEBAUTHN_ORIGIN', env('APP_URL', 'http://localhost')),
    ],

    'risk' => [
        'high_failure_threshold' => (int) env('ACCESS_RISK_HIGH_FAILURE_THRESHOLD', 10),
        'new_location_requires_mfa' => (bool) env('ACCESS_RISK_NEW_LOCATION_REQUIRES_MFA', true),
        'impossible_travel_rejects' => (bool) env('ACCESS_RISK_IMPOSSIBLE_TRAVEL_REJECTS', true),
        'suspend_on_critical_compromise' => (bool) env('ACCESS_RISK_SUSPEND_ON_CRITICAL_COMPROMISE', true),
    ],
];
