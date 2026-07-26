<?php

declare(strict_types=1);

return [
    'modification_token_bytes' => (int) env('VOUCHER_MODIFICATION_TOKEN_BYTES', 32),
    'token_hash_key' => env('VOUCHER_TOKEN_HASH_KEY'),
    'transaction_hmac_key' => env('VOUCHER_TRANSACTION_HMAC_KEY'),
    'idempotency_hmac_key' => env('VOUCHER_IDEMPOTENCY_HMAC_KEY'),
    'search' => [
        'default_page_size' => 20,
        'maximum_page_size' => 100,
    ],
    'rate_limits' => [
        'open_per_minute' => 20,
        'modification_requests_per_minute' => 10,
        'token_attempts_per_minute' => 10,
        'authorizations_per_minute' => 10,
        'fulfillments_per_minute' => 10,
    ],
];
