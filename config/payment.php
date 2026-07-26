<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | M11 idempotency
    |--------------------------------------------------------------------------
    |
    | This HMAC key protects client-supplied idempotency keys at rest. Bank
    | format, folio scope, refund methods and retention are intentionally not
    | configured here because their business contracts remain undefined.
    |
    */
    'idempotency_hmac_key' => env('PAYMENT_IDEMPOTENCY_HMAC_KEY'),
];
