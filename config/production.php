<?php

return [
    'trusted_proxies' => env('TRUSTED_PROXIES'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:4200'),
    'initial_manager' => [
        'enabled' => env('INITIAL_GENERAL_MANAGER_ENABLED', false),
        'name' => env('INITIAL_GENERAL_MANAGER_NAME'),
        'email' => env('INITIAL_GENERAL_MANAGER_EMAIL'),
    ],
    'required' => [
        'app_key' => env('APP_KEY'),
        'db_primary_host' => env('DB_PRIMARY_HOST'),
        'db_replica_host' => env('DB_REPLICA_HOST'),
        'db_database' => env('DB_DATABASE'),
        'db_primary_username' => env('DB_PRIMARY_USERNAME'),
        'db_primary_password' => env('DB_PRIMARY_PASSWORD'),
        'db_replica_username' => env('DB_REPLICA_USERNAME'),
        'db_replica_password' => env('DB_REPLICA_PASSWORD'),
        'redis_host' => env('REDIS_HOST'),
        'redis_password' => env('REDIS_PASSWORD'),
        'storage_key' => env('AWS_ACCESS_KEY_ID'),
        'storage_secret' => env('AWS_SECRET_ACCESS_KEY'),
        'storage_bucket' => env('AWS_BUCKET'),
        'trusted_proxies' => env('TRUSTED_PROXIES'),
        'client_data_hmac_key' => env('CLIENT_DATA_HMAC_KEY'),
        'mail_host' => env('MAIL_HOST'),
        'mail_username' => env('MAIL_USERNAME'),
        'mail_password' => env('MAIL_PASSWORD'),
    ],
];
