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
            $branch = BranchRecord::query()->where('is_headquarters', true)->firstOrFail();
            $manager = $this->existingActor('general_manager');
            $administrator = $this->existingActor('admin');

            $this->activate($manager, 'Gerente General Demo', 'gerente.general@misvales.local');
            $this->activate($administrator, 'Administrador Demo', 'admin@misvales.local');

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

                $role = Role::query()->where('code', $actor['role'])->firstOrFail();
                UserRoleScope::query()->firstOrCreate([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'branch_id' => $branch->id,
                    'scope_type' => 'BRANCH',
                    'status' => 'ACTIVE',
                    'revoked_at' => null,
                ], [
                    'assigned_by_user_id' => $manager->id,
                    'assigned_at' => now(),
                    'assignment_reason' => 'Cuenta para demo local',
                ]);
            }
        });
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
            ['name' => 'Gerente de Sucursal Demo', 'email' => 'gerente.sucursal@misvales.local', 'role' => 'branch_manager'],
            ['name' => 'Coordinador Demo', 'email' => 'coordinador@misvales.local', 'role' => 'coordinator'],
            ['name' => 'Verificador Demo', 'email' => 'verificador@misvales.local', 'role' => 'verifier'],
            ['name' => 'Cajera Demo', 'email' => 'cajera@misvales.local', 'role' => 'cashier'],
        ];
    }
}
