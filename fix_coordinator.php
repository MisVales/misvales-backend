<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$pepe = App\Models\User::where('email', 'pepe@gmail.com')->first();
$distribuidora = App\Models\Distribuidora::where('user_id', $pepe->id)->first();
$jesus = App\Models\User::where('email', 'jesus@gmail.com')->first();
$jorge = App\Models\User::where('email', 'jorge@gmail.com')->first(); // manager

$assignment = App\Models\CoordinatorDistributorAssignment::query()->firstOrCreate(
    ['distributor_id' => $distribuidora->id, 'status' => 'ACTIVE'],
    [
        'coordinator_id' => $jesus->id,
        'branch_id' => $distribuidora->branch_id,
        'valid_from' => now()->subDay(),
        'assigned_by' => $jorge->id,
        'assignment_reason' => 'Fijado manualmente',
        'lock_version' => 1
    ]
);

echo "Assigned Coordinator to Pepe: " . $assignment->id . "\n";
