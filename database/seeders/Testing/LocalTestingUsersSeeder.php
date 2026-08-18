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
    private const PASSWORD = '123456789ggg';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalTestingUsersSeeder solo puede ejecutarse en los entornos local o testing.');
        }

        DB::transaction(function (): void {
            $branch = BranchRecord::query()->where('code', 'MATRIZ')->firstOrFail();

            $managerEmail = trim((string) config('bootstrap.initial_general_manager.email'));
            $managerName = trim((string) config('bootstrap.initial_general_manager.name'));

            if ($managerEmail === '' || $managerName === '') {
                throw new RuntimeException('Configure INITIAL_GENERAL_MANAGER_EMAIL e INITIAL_GENERAL_MANAGER_NAME para el local testing seeder.');
            }

            $manager = $this->upsertUser($managerEmail, $managerName);
            $totpSecret = (string) config('bootstrap.local_testing_totp_secret');

            if ($totpSecret === '') {
                $totpSecret = (new Google2FA)->generateSecretKey();
            }

            $this->assign($manager, 'general_manager', 'GLOBAL');
            $this->seedTotp($manager, $totpSecret);

            foreach ([
                ['qa.gs.torreon@misvales.test', 'Gerente Sucursal Torreón QA', 'branch_manager', 'BRANCH', $branch->id],
                ['qa.coord.a@misvales.test', 'Coordinador A QA', 'coordinator', 'BRANCH', $branch->id],
                ['qa.verificador@misvales.test', 'Verificador QA', 'verifier', 'BRANCH', $branch->id],
                ['qa.admin@misvales.test', 'Administrador QA', 'admin', 'GLOBAL', null],
                ['qa.cajera@misvales.test', 'Cajera QA', 'cashier', 'BRANCH', $branch->id],
            ] as [$email, $name, $roleCode, $scopeType, $branchId]) {
                $user = $this->upsertUser($email, $name);
                $this->assign($user, $roleCode, $scopeType, $branchId, $manager->id);
                $this->seedTotp($user, $totpSecret);
            }
        });
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
    ): void {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        UserRoleScope::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_type' => $scopeType,
            'branch_id' => $branchId,
            'status' => 'ACTIVE',
            'revoked_at' => null,
        ], [
            'scope_id' => null,
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
