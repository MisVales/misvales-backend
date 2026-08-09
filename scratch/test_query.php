<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require_once dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\User::first();
echo 'Email: ' . $user->email . ' | Norm: ' . $user->normalized_email . ' | Match: ' . (App\Models\User::where('normalized_email', 'albertosaut@gmail.com')->exists() ? 'YES' : 'NO') . "\n";
