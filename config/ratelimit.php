<?php

return [
    'enabled' => (bool) env('RATELIMIT', true),
    'broadcasting_auth_per_minute' => (int) env('BROADCAST_AUTH_RATE_LIMIT_PER_MINUTE', 30),
];
