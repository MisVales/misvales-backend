<?php

declare(strict_types=1);

return [
    'idempotency_hmac_key' => env('EXCESS_BALANCE_IDEMPOTENCY_HMAC_KEY', env('APP_KEY')),
];
