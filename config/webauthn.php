<?php

return [
    'rp_id' => env('WEBAUTHN_RP_ID', parse_url((string) env('FRONTEND_URL', 'http://localhost:4200'), PHP_URL_HOST)),
    'origin' => env('WEBAUTHN_ORIGIN', env('FRONTEND_URL', 'http://localhost:4200')),
];
