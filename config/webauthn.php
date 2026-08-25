<?php

$defaultOrigin = (string) env('WEBAUTHN_ORIGIN', env('FRONTEND_URL', 'http://localhost:4200'));
$configuredOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $origin): string => rtrim(mb_strtolower(trim($origin)), '/'),
    explode(',', (string) env('WEBAUTHN_ORIGINS', $defaultOrigin))
))));

return [
    'rp_id' => env('WEBAUTHN_RP_ID', parse_url((string) env('FRONTEND_URL', 'http://localhost:4200'), PHP_URL_HOST)),
    'origins' => $configuredOrigins,
];
