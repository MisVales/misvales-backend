<?php

namespace Database\Seeders;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Permission;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Illuminate\Database\Seeder;

class AccessFoundationSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(
            ['is_headquarters' => true],
            ['name' => 'Sucursal Matriz', 'is_active' => true],
        );

        foreach (RoleCode::cases() as $code) {
            Role::query()->firstOrCreate(
                ['code' => $code->value],
                [
                    'name' => str($code->value)->replace('_', ' ')->title(),
                    'scope' => $code->isGlobal() ? 'GLOBAL' : 'BRANCH',
                    'is_active' => true,
                ],
            );
        }

        foreach (PermissionCode::cases() as $code) {
            Permission::query()->firstOrCreate(
                ['code' => $code->value],
                ['name' => str($code->value)->replace(['.', '_'], ' ')->title(), 'is_active' => true],
            );
        }

        $own = [
            PermissionCode::CONTEXT_READ,
            PermissionCode::SESSIONS_READ_OWN,
            PermissionCode::SESSIONS_REVOKE_OWN,
            PermissionCode::PASSWORD_CHANGE_OWN,
            PermissionCode::MFA_MANAGE_OWN,
        ];
        $matrix = [
            RoleCode::GENERAL_MANAGER->value => [...$own, PermissionCode::ACCOUNTS_GLOBAL_CREATE, PermissionCode::ACCOUNTS_GLOBAL_APPROVE, PermissionCode::ACCOUNTS_GLOBAL_DISABLE, PermissionCode::SECURITY_ALERTS_GLOBAL_READ, PermissionCode::SECURITY_AUDIT_GLOBAL_READ],
            RoleCode::SUCURSAL_MANAGER->value => [...$own, PermissionCode::ACCOUNTS_BRANCH_REQUEST, PermissionCode::ACCOUNTS_BRANCH_DISABLE_REQUEST, PermissionCode::SECURITY_ALERTS_BRANCH_READ],
            RoleCode::COORDINATOR->value => [...$own, PermissionCode::SECURITY_ALERTS_BRANCH_READ],
            RoleCode::VERIFIER->value => $own,
            RoleCode::ADMINISTRATOR->value => [...$own, PermissionCode::SECURITY_ALERTS_GLOBAL_READ, PermissionCode::SECURITY_AUDIT_GLOBAL_READ],
            RoleCode::DISTRIBUTOR->value => $own,
            RoleCode::CASHIER->value => $own,
        ];

        foreach ($matrix as $roleCode => $permissionCodes) {
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $ids = Permission::query()->whereIn('code', array_map(fn (PermissionCode $code) => $code->value, $permissionCodes))->pluck('id');
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }
}
