<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

$now = Carbon::now();

// 1. Get the branch
$branch = DB::table('branches')->where('name', 'Matriz Torreón')->first();
if (!$branch) {
    echo "No Matriz Torreón branch found.\n";
    exit;
}

// 2. Get the role
$role = DB::table('roles')->where('code', 'coordinator')->first();
if (!$role) {
    echo "No coordinator role found.\n";
    exit;
}

// 3. Get or Create the user
$user = DB::table('users')->where('normalized_email', 'COORDINADOR@MISVALES.COM')->first();
if (!$user) {
    $userId = Str::uuid()->toString();
    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Coordinador de Prueba',
        'email' => 'coordinador@misvales.com',
        'normalized_email' => 'COORDINADOR@MISVALES.COM',
        'state' => 'ACTIVE',
        'password' => Hash::make('password'),
        'email_verified_at' => $now,
        'mfa_enrolled_at' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
} else {
    $userId = $user->id;
}

// 4. Assign role and scope to branch
$adminUser = DB::table('users')->where('email', 'admin@misvales.com')->first();
$adminId = $adminUser ? $adminUser->id : $userId;

DB::table('user_role_scopes')->insert([
    'id' => Str::uuid()->toString(),
    'user_id' => $userId,
    'role_id' => $role->id,
    'branch_id' => $branch->id,
    'scope_type' => 'BRANCH',
    'status' => 'ACTIVE',
    'assigned_by_user_id' => $adminId,
    'assigned_at' => $now,
    'assignment_reason' => 'Prueba de coordinador',
    'created_at' => $now,
    'updated_at' => $now,
]);

echo "Coordinador creado con éxito!\n";
