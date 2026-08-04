<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitialGeneralManagerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $now = Carbon::now();

            // 1. Crear o recuperar el rol GENERAL_MANAGER
            $roleCode = 'general_manager';
            $role = DB::table('roles')->where('code', $roleCode)->first();

            if (! $role) {
                $roleId = Str::uuid()->toString();
                DB::table('roles')->insert([
                    'id' => $roleId,
                    'code' => $roleCode,
                    'name' => 'Gerente general',
                    'description' => 'Administrador global del sistema',
                    'default_scope' => 'GLOBAL',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $role = DB::table('roles')->where('id', $roleId)->first();
            }

            $managerEnabled = env('INITIAL_GENERAL_MANAGER_ENABLED', false);
            if (! $managerEnabled) {
                return; // Skip if not enabled
            }

            // 2. Crear o recuperar al usuario gerente general
            $managerName = env('INITIAL_GENERAL_MANAGER_NAME');
            $managerEmail = env('INITIAL_GENERAL_MANAGER_EMAIL');

            if (! $managerName || ! $managerEmail) {
                throw new \Exception('Se requieren las variables de entorno INITIAL_GENERAL_MANAGER_NAME e INITIAL_GENERAL_MANAGER_EMAIL para inicializar el sistema.');
            }

            $user = DB::table('users')->where('email', $managerEmail)->first();

            if (! $user) {
                $userId = Str::uuid()->toString();
                DB::table('users')->insert([
                    'id' => $userId,
                    'name' => $managerName,
                    'email' => $managerEmail,
                    'normalized_email' => strtoupper($managerEmail),
                    'state' => 'PENDING_ACTIVATION',
                    'password' => null,
                    'email_verified_at' => null,
                    'mfa_enrolled_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $user = DB::table('users')->where('id', $userId)->first();
            }

            // 3. Crear o recuperar la sucursal matriz
            $branchCode = 'MATRIZ';
            $branch = DB::table('branches')->where('code', $branchCode)->first();

            if (! $branch) {
                $branchId = Str::uuid()->toString();
                DB::table('branches')->insert([
                    'id' => $branchId,
                    'code' => $branchCode,
                    'name' => 'Matriz Torreón',
                    'is_headquarters' => true,
                    'status' => 'ACTIVE',
                    'lock_version' => 0,
                    'created_by' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $branch = DB::table('branches')->where('id', $branchId)->first();
            }

            // 4. Asignar el rol y alcance global desde la matriz
            $scopeExists = DB::table('user_role_scopes')
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->where('status', 'ACTIVE')
                ->whereNull('valid_to')
                ->exists();

            if (! $scopeExists) {
                DB::table('user_role_scopes')->insert([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'branch_id' => $branch->id,
                    'scope_type' => 'GLOBAL',
                    'valid_from' => $now,
                    'valid_to' => null,
                    'status' => 'ACTIVE',
                    'assigned_by' => $user->id,
                    'reason' => 'Bootstrap inicial del sistema',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // 5. Crear la invitación de activación
            $invitationExists = DB::table('account_invitations')
                ->where('user_id', $user->id)
                ->where('state', 'ACTIVE')
                ->where('expires_at', '>', $now)
                ->exists();

            if (! $invitationExists && $user->state === 'PENDING_ACTIVATION') {
                $token = Str::random(40);
                DB::table('account_invitations')->insert([
                    'id' => Str::uuid()->toString(),
                    'user_id' => $user->id,
                    'created_by_user_id' => $user->id,
                    'purpose' => 'ACCOUNT_ACTIVATION',
                    'token_hash' => hash('sha256', $token),
                    'state' => 'ACTIVE',
                    'expires_at' => $now->copy()->addDays(7),
                    'attempt_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                dump('Generated Token: '.$token);
            }
        });
    }
}
