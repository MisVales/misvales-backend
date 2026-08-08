<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require_once dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$u = App\Models\User::create([
    'id' => Illuminate\Support\Str::uuid(), 
    'name' => 'Test', 
    'email' => 'TEST@TEST.COM', 
    'normalized_email' => 'test@test.com', 
    'password' => 'test'
]);
echo "Model instance: " . $u->normalized_email . "\n";
echo "Database fetch: " . App\Models\User::find($u->id)->normalized_email . "\n";
$u->delete();
