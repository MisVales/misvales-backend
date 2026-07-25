<?php

declare(strict_types=1);

return [
    'fifty_percent_tolerance' => env('CREDIT_FIFTY_PERCENT_TOLERANCE', '500.0000'),
    'percentage' => '0.5000',
    'page_size' => (int) env('CREDIT_PAGE_SIZE', 25),
    'max_page_size' => (int) env('CREDIT_MAX_PAGE_SIZE', 100),
    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'America/Monterrey'),
];
