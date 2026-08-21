<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
$allowedOrigins = [...config('cors.allowed_origins', []), config('production.frontend_url'), config('app.url')];
echo json_encode($allowedOrigins);
