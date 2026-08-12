<?php

return [
    'timezone' => env('RELATIONS_TIMEZONE', 'America/Monterrey'),
    'cutoff_day' => (int) env('RELATIONS_CUTOFF_DAY', 25),
    'cutoff_time' => env('RELATIONS_CUTOFF_TIME', '00:05'),
    'payment_deadline_days' => (int) env('RELATIONS_PAYMENT_DEADLINE_DAYS', 20),
    'advance_period_days' => env('RELATIONS_ADVANCE_PERIOD_DAYS'),
    'bank' => [
        'name' => env('RELATIONS_BANK_NAME'),
        'beneficiary' => env('RELATIONS_BANK_BENEFICIARY'),
        'clabe' => env('RELATIONS_BANK_CLABE'),
    ],
];
