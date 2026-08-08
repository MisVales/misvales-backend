<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require_once dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = new App\Models\User();
$user->password = 'secret123';
echo "Plain assignment: " . $user->password . "\n";
echo "Hash check plain: " . (Hash::check('secret123', $user->password) ? 'YES' : 'NO') . "\n";

$user->password = Hash::make('secret123');
echo "Hashed assignment: " . $user->password . "\n";
echo "Hash check hashed: " . (Hash::check('secret123', $user->password) ? 'YES' : 'NO') . "\n";
