<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class LocalDevSeeder extends Seeder
{
    public function run()
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $now = Carbon::now();
        $userId = Str::uuid()->toString();

        DB::table('users')->insert([
            'id' => $userId,
            'name' => 'Administrador Local',
            'email' => 'admin@misvales.com',
            'normalized_email' => 'ADMIN@MISVALES.COM',
            'state' => 'ACTIVE',
            'password' => Hash::make('password'),
            'email_verified_at' => $now,
            'mfa_enrolled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $branchId = Str::uuid()->toString();
        DB::table('branches')->insert([
            'id' => $branchId,
            'code' => 'MATRIZ',
            'name' => 'Matriz Torreón',
            'is_headquarters' => true,
            'status' => 'ACTIVE',
            'lock_version' => 0,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $role = DB::table('roles')->where('code', 'general_manager')->first();
        if (!$role) {
            $roleId = Str::uuid()->toString();
            DB::table('roles')->insert([
                'id' => $roleId,
                'code' => 'general_manager',
                'name' => 'Gerente general',
                'description' => 'Administrador',
                'default_scope' => 'GLOBAL',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $role = DB::table('roles')->where('id', $roleId)->first();
        }

        DB::table('user_role_scopes')->insert([
            'id' => Str::uuid()->toString(),
            'user_id' => $userId,
            'role_id' => $role->id,
            'branch_id' => $branchId,
            'scope_type' => 'GLOBAL',
            'valid_from' => $now,
            'valid_to' => null,
            'status' => 'ACTIVE',
            'assigned_by' => $userId,
            'reason' => 'Dev',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        echo "User created: admin@misvales.com / password\n";
    }
}
