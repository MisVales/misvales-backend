<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'pepe@gmail.com')->first();
if (!$user) {
    echo "Pepe not found.\n";
    exit;
}

$scopes = $user->roleScopes()->with('role')->get()->toArray();
$perms = $user->getAllPermissions()->pluck('code')->toArray();

echo json_encode([
    'scopes' => $scopes,
    'permissions' => $perms,
    'branch' => $user->distribuidora?->branch_id,
], JSON_PRETTY_PRINT);
