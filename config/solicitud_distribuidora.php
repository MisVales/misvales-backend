<?php

return [
    'vehicle_catalog' => [
        'vpic_base_url' => env('VPIC_BASE_URL', 'https://vpic.nhtsa.dot.gov/api/vehicles'),
        'fresh_seconds' => 86400,
        'stale_seconds' => 2592000,
    ],
];
