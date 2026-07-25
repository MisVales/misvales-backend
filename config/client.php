<?php

declare(strict_types=1);

return [
    'hmac_key' => env('CLIENT_HMAC_KEY') ?: env('APP_KEY'),
    'pagination' => [
        'default' => 20,
        'maximum' => 100,
    ],
    'portfolio_note_max_length' => 1000,
];
