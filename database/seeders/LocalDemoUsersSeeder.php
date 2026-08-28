<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class LocalDemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $matrix = BranchRecord::query()
                ->where('code', 'MATRIZ')
                ->where('status', 'ACTIVE')
                ->firstOrFail();
            $branch = $this->matamorosBranch($matrix);
            $manager = $this->existingActor('general_manager');
            $administrator = $this->existingActor('admin');

            $this->activate($manager, 'Gerente General Demo', 'gerentegeneral@gmail.com');
            $this->activate($administrator, 'Administrador Demo', 'administrador@gmail.com');
            $this->clearBranchAssignments($manager);
            $this->clearBranchAssignments($administrator);
            $this->assignToBranch($manager, 'general_manager', $matrix, $manager);
            $this->assignToBranch($administrator, 'admin', $branch, $manager);

            foreach ($this->branchActors() as $actor) {
                $user = User::query()->updateOrCreate(
                    ['normalized_email' => $actor['email']],
                    [
                        'name' => $actor['name'],
                        'email' => $actor['email'],
                        'password' => Hash::make('1234'),
                        'state' => 'ACTIVE',
                        'email_verified_at' => now(),
                        'password_changed_at' => now(),
                    ],
                );

                $this->clearBranchAssignments($user);
                $this->assignToBranch($user, $actor['role'], $branch, $manager);
            }
        });
    }

    private function clearBranchAssignments(User $user): void
    {
        UserRoleScope::query()
            ->where('user_id', $user->id)
            ->where('scope_type', 'BRANCH')
            ->where('status', 'ACTIVE')
            ->update([
                'status' => 'REVOKED',
                'revoked_at' => now(),
                'revoked_by_user_id' => $user->id,
                'revocation_reason' => 'Reasignación de cuentas demo locales por sucursal.',
            ]);
    }

    private function matamorosBranch(BranchRecord $matrix): BranchRecord
    {
        $branch = BranchRecord::query()
            ->where('code', 'MATAMOROS')
            ->orWhere(fn ($query) => $query
                ->where('name', 'Sucursal Matamoros')
                ->where('is_headquarters', false))
            ->first() ?? new BranchRecord;

        $branch->fill([
            'code' => 'MATAMOROS',
            'name' => 'Sucursal Matamoros',
            'address' => 'C. PabellÃ³n 28, Centro, 27440 Matamoros, Coah.',
            'address_validation_id' => null,
            'address_place_id' => null,
            'address_latitude' => 25.5280208,
            'address_longitude' => -103.2300172,
            'address_validated_at' => null,
            'is_headquarters' => false,
            'status' => 'ACTIVE',
            'lock_version' => $branch->exists ? $branch->lock_version : 0,
            'created_by' => $branch->exists ? $branch->created_by : $matrix->created_by,
            'updated_by' => $matrix->created_by,
        ]);
        $branch->save();

        return $branch;
    }

    private function assignToBranch(User $user, string $roleCode, BranchRecord $branch, User $manager): void
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        UserRoleScope::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'scope_type' => 'BRANCH',
            'status' => 'ACTIVE',
            'revoked_at' => null,
        ], [
            'scope_id' => null,
            'assigned_by_user_id' => $manager->id,
            'assigned_at' => now(),
            'assignment_reason' => 'Cuenta para demo local en Sucursal Matamoros',
        ]);
    }

    private function existingActor(string $role): User
    {
        return User::query()->whereHas('roleScopes', fn ($query) => $query
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', $role)))
            ->oldest('created_at')
            ->firstOrFail();
    }

    private function activate(User $user, string $name, string $email): void
    {
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'normalized_email' => $email,
            'password' => Hash::make('1234'),
            'state' => 'ACTIVE',
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password_changed_at' => now(),
        ])->save();
    }

    /** @return list<array{name: string, email: string, role: string}> */
    private function branchActors(): array
    {
        return [
            ['name' => 'Gerente de Sucursal Demo', 'email' => 'gerentesucursal@gmail.com', 'role' => 'branch_manager'],
            ['name' => 'Coordinador Demo', 'email' => 'coordinador@gmail.com', 'role' => 'coordinator'],
            ['name' => 'Verificador Demo', 'email' => 'verificador@gmail.com', 'role' => 'verifier'],
            ['name' => 'Cajera Demo', 'email' => 'cajera@gmail.com', 'role' => 'cashier'],
        ];
    }
}
