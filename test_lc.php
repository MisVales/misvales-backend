<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pepe = App\Models\User::where('email', 'pepe@gmail.com')->first();
$distribuidora = App\Models\Distribuidora::where('user_id', $pepe->id)->first();

$lc = App\Models\LineaCredito::query()->firstOrCreate(
    ['distributor_id' => $distribuidora->id],
    ['total_authorized' => '100000.0000', 'used_balance' => '0.0000', 'lock_version' => 1]
);

echo "Created LC: " . $lc->id . "\n";
