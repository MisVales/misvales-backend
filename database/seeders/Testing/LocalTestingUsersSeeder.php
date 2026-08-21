<?php

declare(strict_types=1);

namespace Database\Seeders\Testing;

use App\Models\MfaCredential;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;

final class LocalTestingUsersSeeder extends Seeder
{
    private const PASSWORD = '1234';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalTestingUsersSeeder solo puede ejecutarse en los entornos local o testing.');
        }

        DB::transaction(function (): void {
            $manager = $this->upsertUser('alberto@gmail.com', 'Alberto');
            $totpSecret = (string) config('bootstrap.local_testing_totp_secret');

            if ($totpSecret === '') {
                $totpSecret = (new Google2FA)->generateSecretKey();
            }

            $this->assign($manager, 'general_manager', 'GLOBAL');
            $this->seedTotp($manager, $totpSecret);

            $branch = $this->upsertMatamorosBranch($manager->id);

            foreach ([
                ['admin@gmail.com', 'Administrador QA', 'admin', 'GLOBAL', null],
                ['jorge@gmail.com', 'Jorge Ibarra', 'branch_manager', 'BRANCH', $branch->id],
                ['dani@gmail.com', 'Daniel Garcia', 'coordinator', 'BRANCH', $branch->id],
                ['jesus@gmail.com', 'Jesus Guillen', 'coordinator', 'BRANCH', $branch->id],
                ['saul@gmail.com', 'Saul Sanchez', 'verifier', 'BRANCH', $branch->id],
                ['aza@gmail.com', 'Azael Garcia', 'cashier', 'BRANCH', $branch->id],
                ['pepe@gmail.com', 'Pepe', 'distributor', 'DISTRIBUTOR', $branch->id],
            ] as [$email, $name, $roleCode, $scopeType, $branchId]) {
                $user = $this->upsertUser($email, $name);
                
                $scopeId = null;
                if ($roleCode === 'distributor' && $branchId !== null) {
                    $distribuidora = $this->setupDistributor($user, $branchId, $manager->id);
                    $scopeId = $distribuidora->id;
                }
                
                $this->assign($user, $roleCode, $scopeType, $branchId, $manager->id, $scopeId);
                $this->seedTotp($user, $totpSecret);
            }
        });
    }

    private function setupDistributor(User $distributor, string $branchId, string $managerId): \App\Models\Distribuidora
    {
        $coordinator = User::where('normalized_email', 'jesus@gmail.com')->first() ?? User::find($managerId);

        // Generar un sufijo numérico basado en el id del distribuidor para evitar colisiones
        $suffix = '99' . str_pad((string)(crc32($distributor->email) % 10000), 4, '0', STR_PAD_LEFT);

        $solicitud = \App\Models\DistributorApplication::firstOrCreate(
            ['application_number' => 'SOL-2026-' . $suffix],
            [
                'branch_id' => $branchId,
                'coordinator_id' => $coordinator->id,
                'created_by' => $managerId,
                'status' => 'DRAFT',
                'section_declarations' => [],
                'lock_version' => 1,
            ]
        );

        $distribuidora = \App\Models\Distribuidora::firstOrNew(['user_id' => $distributor->id]);
        if (! $distribuidora->exists) {
            $distribuidora->forceFill([
                'application_id' => $solicitud->id,
                'distributor_number' => 'DIS-2026-' . $suffix,
                'branch_id' => $branchId,
                'status' => 'ACTIVE',
                'activated_at' => now(),
                'activated_by' => $managerId,
                'lock_version' => 1,
            ])->save();
        }

        \App\Models\LineaCredito::query()->firstOrCreate(
            ['distributor_id' => $distribuidora->id],
            ['total_authorized' => '100000.0000', 'used_balance' => '0.0000', 'lock_version' => 1]
        );

        $categoria = \App\Models\CategoryVersion::whereHas('category', function ($query) {
            $query->where('code', 'CAT-PLATA');
        })->first();

        if ($categoria) {
            \App\Models\AsignacionCategoriaDistribuidora::query()->firstOrCreate(
                ['distributor_id' => $distribuidora->id, 'ends_at' => null],
                [
                    'category_version_id' => $categoria->id,
                    'starts_at' => now()->subDay(),
                    'assigned_by' => $managerId,
                    'reason' => 'Pruebas de desarrollo',
                ]
            );
        }

        \App\Models\CoordinatorDistributorAssignment::query()->firstOrCreate(
            ['distributor_id' => $distribuidora->id, 'status' => 'ACTIVE'],
            [
                'coordinator_id' => $coordinator->id,
                'branch_id' => $branchId,
                'valid_from' => now()->subDay(),
                'assigned_by' => $managerId,
                'assignment_reason' => 'Pruebas de desarrollo',
                'lock_version' => 1
            ]
        );

        return $distribuidora;
    }

    private function upsertMatamorosBranch(string $managerId): BranchRecord
    {
        $branch = BranchRecord::query()->firstOrNew(['code' => 'MATAMOROS']);
        $branch->fill([
            'name' => 'Sucursal Matamoros',
            'address' => null,
            'address_validation_id' => null,
            'address_place_id' => null,
            'address_latitude' => null,
            'address_longitude' => null,
            'address_validated_at' => null,
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => 0,
        ]);
        $branch->created_by ??= $managerId;
        $branch->updated_by = $branch->exists ? $managerId : null;
        $branch->save();

        return $branch;
    }

    private function upsertUser(string $email, string $name): User
    {
        $normalizedEmail = Str::lower(trim($email));
        $user = User::query()->firstOrNew(['normalized_email' => $normalizedEmail]);
        $user->name = $name;
        $user->email = $email;
        $user->state = 'ACTIVE';
        $user->password = Hash::make(self::PASSWORD);
        $user->email_verified_at ??= now();
        $user->password_changed_at ??= now();
        $user->require_password_change = false;
        $user->save();

        return $user;
    }

    private function assign(
        User $user,
        string $roleCode,
        string $scopeType,
        ?string $branchId = null,
        ?string $assignedBy = null,
        ?string $scopeId = null,
    ): void {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        UserRoleScope::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'branch_id' => $branchId,
            'scope_id' => $scopeId,
            'status' => 'ACTIVE',
            'revoked_at' => null,
        ], [
            'assigned_by_user_id' => $assignedBy ?? $user->id,
            'assigned_at' => now(),
            'assignment_reason' => 'Usuario auxiliar de pruebas locales',
        ]);
    }

    private function seedTotp(User $user, string $secret): void
    {
        MfaCredential::query()->updateOrCreate([
            'user_id' => $user->id,
            'type' => 'TOTP',
        ], [
            'label' => 'QA local/testing',
            'confirmed_at' => now(),
            'revoked_at' => null,
            'secret_ciphertext' => Crypt::encryptString($secret),
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ]);
    }
}
