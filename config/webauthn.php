<?php

$rawRpId = (string) env('WEBAUTHN_RP_ID', '');
if ($rawRpId === '') {
    $rawFrontend = (string) env('FRONTEND_URL', 'https://safeacces.lat');
    $firstFrontend = explode(',', $rawFrontend)[0] ?? 'https://safeacces.lat';
    $rawRpId = parse_url(trim($firstFrontend), PHP_URL_HOST) ?? 'safeacces.lat';
} elseif (str_starts_with($rawRpId, 'http://') || str_starts_with($rawRpId, 'https://')) {
    $rawRpId = parse_url($rawRpId, PHP_URL_HOST) ?? $rawRpId;
}

$rpId = trim($rawRpId);

$defaultOrigins = [
    'http://localhost:4200',
    'https://safeacces.lat',
    'https://vpn.safeacces.lat',
];

$allRaw = [
    ...$defaultOrigins,
    ...explode(',', (string) env('WEBAUTHN_ORIGINS', '')),
    ...explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
    (string) env('WEBAUTHN_ORIGIN', ''),
    (string) env('FRONTEND_URL', ''),
    (string) env('VPN_FRONTEND_URL', env('VPN_URL', '')),
];

$configuredOrigins = array_values(array_unique(array_filter(array_map(
    static function (string $origin): string {
        $trimmed = trim($origin);
        if ($trimmed === '' || ! str_contains($trimmed, '://')) {
            return '';
        }

        return rtrim(mb_strtolower($trimmed), '/');
    },
    $allRaw
))));

return [
    'rp_id' => $rpId ?: 'safeacces.lat',
    'origins' => $configuredOrigins,
];

