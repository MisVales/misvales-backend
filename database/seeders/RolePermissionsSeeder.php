<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RolePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $grantorId = User::query()
                ->whereHas('roleScopes', fn ($query) => $query
                    ->where('status', 'ACTIVE')
                    ->whereNull('revoked_at')
                    ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager')))
                ->oldest('created_at')
                ->value('id');

            $permissionsByCode = Permission::query()
                ->where('is_active', true)
                ->get(['id', 'code'])
                ->keyBy('code');

            foreach ($this->matrix(array_keys($permissionsByCode->all())) as $roleCode => $permissionCodes) {
                $role = Role::query()->where('code', $roleCode)->firstOrFail(['id']);

                foreach ($permissionCodes as $permissionCode) {
                    $permission = $permissionsByCode->get($permissionCode);

                    if ($permission === null) {
                        continue;
                    }

                    $hasHistory = DB::table('role_permissions')
                        ->where('role_id', $role->id)
                        ->where('permission_id', $permission->id)
                        ->exists();

                    if ($hasHistory) {
                        continue;
                    }

                    DB::table('role_permissions')->insert([
                        'id' => Str::uuid()->toString(),
                        'role_id' => $role->id,
                        'permission_id' => $permission->id,
                        'granted_by_user_id' => $grantorId,
                        'granted_at' => now(),
                    ]);
                }
            }
        });
    }

    /** @param list<string> $allPermissionCodes
     * @return array<string, list<string>>
     */
    private function matrix(array $allPermissionCodes): array
    {
        return [
            'general_manager' => $allPermissionCodes,
            'branch_manager' => [
                'users.view', 'users.create', 'users.update', 'users.manage_state',
                'roles.view', 'roles.assign', 'branches.view', 'assignments.manage',
                'catalogs.view_published', 'catalogs.view_history',
                'distributor_applications.view', 'distributor_applications.view_sensitive',
                'distributor_applications.create', 'distributor_applications.update',
                'distributor_applications.submit',
                'distributors.view_any', 'distributors.view', 'distributors.activate',
                'distributors.assign_category', 'distributors.view_category_history',
                'distributors.resend_activation', 'distributors.view_initial_credit',
                'clients.view', 'clients.view_sensitive', 'clients.view_assignment_history',
                'clients.view_bank_accounts', 'clients.view_portfolio',
                'credit_lines.view_branch', 'credit_line_movements.view_branch',
                'credit_increase_requests.view_branch', 'credit_increase_requests.decide_branch',
                'vouchers.view_branch', 'voucher_modifications.authorize_branch',
                'relations.view_branch', 'relations.download_branch',
                'bank_imports.view_branch', 'bank_movements.view_branch',
                'manual_reconciliation.authorize_branch', 'surpluses.view_branch',
                'refunds.authorize_branch',
                'risk.view_branch', 'delinquency.decide_branch', 'delinquency_removal.decide_branch',
                'client_transfers.view', 'organization_changes.view', 'organization_changes.manage_branch',
                'notifications.view_own', 'reports.view_branch', 'audit.view_branch', 'logs.view_branch',
                'media.upload', 'media.download_branch',
            ],
            'coordinator' => [
                'distributor_applications.view', 'distributor_applications.view_sensitive',
                'distributor_applications.create', 'distributor_applications.update',
                'distributor_applications.submit', 'distributors.view_any', 'distributors.view',
                'distributors.view_category_history', 'distributors.view_initial_credit',
                'clients.view', 'clients.view_assignment_history', 'clients.view_bank_accounts',
                'clients.view_portfolio', 'credit_lines.view_assigned', 'credit_line_movements.view_assigned',
                'credit_increase_requests.view_assigned', 'credit_increase_requests.preauthorize_assigned',
                'credit_increase_requests.reject_assigned', 'vouchers.view_assigned',
                'voucher_modifications.authorize_branch', 'bank_movements.view_branch',
                'manual_reconciliation.authorize_branch', 'risk.view_assigned',
                'delinquency_removal.request_assigned', 'client_transfers.view',
                'client_transfers.decide_assigned', 'notifications.view_own',
                'media.upload', 'media.download_branch',
            ],
            'verifier' => [
                'distributor_applications.view', 'distributor_applications.view_sensitive',
                'notifications.view_own', 'media.upload', 'media.download_branch',
            ],
            'admin' => [
                'users.view', 'roles.view', 'branches.view', 'catalogs.view_published',
                'catalogs.view_history', 'distributor_applications.view',
                'distributors.view_any', 'distributors.view', 'distributors.view_category_history',
                'distributors.view_initial_credit', 'clients.view', 'clients.view_assignment_history',
                'clients.view_bank_accounts', 'clients.view_portfolio', 'credit_lines.view_global',
                'credit_line_movements.view_global', 'credit_increase_requests.view_global',
                'vouchers.view_global', 'relations.view_global', 'bank_imports.view_global',
                'bank_movements.view_global', 'surpluses.view_global', 'risk.view_global',
                'client_transfers.view', 'organization_changes.view', 'notifications.view_own',
                'reports.view_global', 'audit.view_global', 'logs.view_global',
            ],
            'distributor' => [
                'distributors.view', 'distributors.view_category_history', 'distributors.view_initial_credit',
                'clients.view', 'clients.create', 'clients.view_bank_accounts',
                'clients.manage_bank_accounts', 'clients.view_portfolio', 'clients.manage_portfolio',
                'credit_lines.view_own', 'credit_line_movements.view_own',
                'credit_increase_requests.create_own', 'credit_increase_requests.view_own',
                'vouchers.create_own', 'vouchers.view_own', 'relations.view_own',
                'relations.download_own', 'payment_clarifications.create_own', 'surpluses.view_own',
                'risk.view_own', 'client_transfers.view',
                'client_transfers.initiate_own', 'client_transfers.receive_own',
                'notifications.view_own', 'media.upload',
            ],
            'cashier' => [
                'vouchers.view_branch', 'vouchers.cash_branch', 'relations.view_branch',
                'bank_imports.create_branch', 'bank_imports.view_branch', 'bank_movements.view_branch',
                'manual_reconciliation.request_branch', 'manual_reconciliation.execute_branch',
                'surpluses.view_branch', 'refunds.execute_branch', 'notifications.view_own',
                'media.upload', 'media.download_branch',
            ],
        ];
    }
}
