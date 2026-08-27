<?php

$configuredOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:4200'))
)));

$frontendOrigin = rtrim(trim((string) env('FRONTEND_URL', 'http://localhost:4200')), '/');

if ($frontendOrigin !== '') {
    $configuredOrigins[] = $frontendOrigin;
}

$configuredOrigins = array_values(array_unique($configuredOrigins));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $configuredOrigins,

    // La API sólo acepta orígenes explícitos; las credenciales no deben
    // habilitarse para patrones amplios ni dominios de terceros.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id', 'X-Correlation-Id', 'X-Trace-Id'],

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    'supports_credentials' => true,

];
