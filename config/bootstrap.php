<?php

declare(strict_types=1);

return [
    'initial_general_manager' => [
        'enabled' => env('INITIAL_GENERAL_MANAGER_ENABLED', false),
        'name' => env('INITIAL_GENERAL_MANAGER_NAME'),
        'email' => env('INITIAL_GENERAL_MANAGER_EMAIL'),
    ],
    'initial_admin' => [
        'enabled' => env('INITIAL_ADMIN_ENABLED', false),
        'name' => env('INITIAL_ADMIN_NAME'),
        'email' => env('INITIAL_ADMIN_EMAIL'),
    ],
    'local_super_session' => [
        'enabled' => env('LOCAL_SUPER_SESSION_ENABLED', false),
        'email' => env('LOCAL_SUPER_SESSION_EMAIL', 'codex-local-session@invalid.test'),
    ],
    'local_testing_totp_secret' => env('LOCAL_TESTING_TOTP_SECRET'),
];
