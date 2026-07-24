<?php

return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 2),
        'time' => (int) env('ARGON_TIME', 3),
        'verify' => true,
    ],
    'rehash_on_login' => true,
];
