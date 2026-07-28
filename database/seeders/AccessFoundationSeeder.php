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
            RoleCode::GENERAL_MANAGER->value => [
                ...$own, PermissionCode::ACCOUNTS_GLOBAL_CREATE, PermissionCode::ACCOUNTS_GLOBAL_APPROVE,
                PermissionCode::ACCOUNTS_GLOBAL_DISABLE, PermissionCode::SECURITY_ALERTS_GLOBAL_READ,
                PermissionCode::SECURITY_AUDIT_GLOBAL_READ, PermissionCode::ONBOARDING_APPLICATIONS_VIEW_GLOBAL,
                PermissionCode::ONBOARDING_APPLICATIONS_AUTHORIZE_GLOBAL, PermissionCode::ONBOARDING_EVIDENCE_VIEW,
                PermissionCode::ONBOARDING_HISTORY_VIEW, PermissionCode::CLIENTS_VIEW_GLOBAL,
                PermissionCode::CONFIGURATION_VIEW_CURRENT, PermissionCode::CONFIGURATION_VIEW_HISTORY,
                PermissionCode::CONFIGURATION_MANAGE, PermissionCode::CONFIGURATION_PUBLISH,
                PermissionCode::CATEGORY_VIEW, PermissionCode::CATEGORY_MANAGE, PermissionCode::CATEGORY_PUBLISH,
                PermissionCode::PRODUCT_VIEW, PermissionCode::PRODUCT_MANAGE, PermissionCode::PRODUCT_PUBLISH,
                PermissionCode::REDEMPTION_PERIOD_VIEW, PermissionCode::REDEMPTION_PERIOD_MANAGE,
                PermissionCode::VOUCHERS_VIEW, PermissionCode::VOUCHER_MODIFICATIONS_VIEW,
                PermissionCode::VOUCHER_MODIFICATIONS_DECIDE,
                PermissionCode::PAYMENTS_VIEW_GLOBAL, PermissionCode::BANK_IMPORTS_RETRY_GLOBAL,
                PermissionCode::MANUAL_RECONCILIATIONS_AUTHORIZE_GLOBAL,
                PermissionCode::EXCESS_BALANCES_VIEW_GLOBAL, PermissionCode::REFUNDS_VIEW_GLOBAL,
                PermissionCode::REFUNDS_AUTHORIZE_GLOBAL, PermissionCode::REFUND_EVIDENCE_VIEW,
                PermissionCode::PAYMENT_EVIDENCE_VIEW,
                PermissionCode::POINTS_VIEW_GLOBAL, PermissionCode::POINT_REDEMPTIONS_DECIDE_GLOBAL,
                PermissionCode::POINTS_RUNS_VIEW_GLOBAL,
                PermissionCode::RISK_VIEW_GLOBAL, PermissionCode::DELINQUENCY_APPLY_GLOBAL,
                PermissionCode::DELINQUENCY_REMOVAL_DECIDE_GLOBAL,
                PermissionCode::MOBILITY_VIEW_GLOBAL, PermissionCode::MOBILITY_REASSIGN_GLOBAL,
                PermissionCode::MOBILITY_BRANCH_CHANGE_GLOBAL,
                PermissionCode::MOBILITY_COORDINATOR_REASSIGN_GLOBAL,
                PermissionCode::REPORTS_VIEW_GLOBAL,
            ],
            RoleCode::SUCURSAL_MANAGER->value => [
                ...$own, PermissionCode::ACCOUNTS_BRANCH_REQUEST, PermissionCode::ACCOUNTS_BRANCH_DISABLE_REQUEST,
                PermissionCode::SECURITY_ALERTS_BRANCH_READ, PermissionCode::ONBOARDING_APPLICATIONS_VIEW_BRANCH,
                PermissionCode::ONBOARDING_APPLICATIONS_AUTHORIZE_BRANCH, PermissionCode::ONBOARDING_EVIDENCE_VIEW,
                PermissionCode::ONBOARDING_HISTORY_VIEW, PermissionCode::CLIENTS_VIEW_BRANCH,
                PermissionCode::CONFIGURATION_VIEW_CURRENT, PermissionCode::CATEGORY_VIEW,
                PermissionCode::PRODUCT_VIEW, PermissionCode::REDEMPTION_PERIOD_VIEW,
                PermissionCode::VOUCHERS_VIEW, PermissionCode::VOUCHER_MODIFICATIONS_VIEW,
                PermissionCode::VOUCHER_MODIFICATIONS_DECIDE,
                PermissionCode::PAYMENTS_VIEW_BRANCH, PermissionCode::BANK_IMPORTS_RETRY_BRANCH,
                PermissionCode::CLARIFICATIONS_REVIEW_BRANCH,
                PermissionCode::MANUAL_RECONCILIATIONS_AUTHORIZE_BRANCH,
                PermissionCode::EXCESS_BALANCES_VIEW_BRANCH, PermissionCode::REFUNDS_VIEW_BRANCH,
                PermissionCode::REFUNDS_AUTHORIZE_BRANCH, PermissionCode::REFUND_EVIDENCE_VIEW,
                PermissionCode::PAYMENT_EVIDENCE_VIEW,
                PermissionCode::POINTS_VIEW_BRANCH, PermissionCode::POINT_REDEMPTIONS_DECIDE_BRANCH,
                PermissionCode::RISK_VIEW_BRANCH, PermissionCode::DELINQUENCY_APPLY_BRANCH,
                PermissionCode::DELINQUENCY_REMOVAL_DECIDE_BRANCH,
                PermissionCode::MOBILITY_VIEW_BRANCH, PermissionCode::MOBILITY_REASSIGN_BRANCH,
                PermissionCode::MOBILITY_BRANCH_CHANGE_BRANCH,
                PermissionCode::MOBILITY_COORDINATOR_REASSIGN_BRANCH,
                PermissionCode::REPORTS_VIEW_BRANCH,
            ],
            RoleCode::COORDINATOR->value => [
                ...$own, PermissionCode::SECURITY_ALERTS_BRANCH_READ,
                PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED, PermissionCode::ONBOARDING_APPLICATIONS_REVIEW,
                PermissionCode::ONBOARDING_APPLICATIONS_CORRECT, PermissionCode::ONBOARDING_APPLICATIONS_EVALUATE,
                PermissionCode::ONBOARDING_EVIDENCE_VIEW, PermissionCode::ONBOARDING_HISTORY_VIEW,
                PermissionCode::CLIENTS_VIEW_ASSIGNED, PermissionCode::PRODUCT_VIEW, PermissionCode::CATEGORY_VIEW,
                PermissionCode::VOUCHERS_VIEW, PermissionCode::VOUCHER_MODIFICATIONS_VIEW,
                PermissionCode::VOUCHER_MODIFICATIONS_DECIDE,
                PermissionCode::PAYMENTS_VIEW_ASSIGNED,
                PermissionCode::MANUAL_RECONCILIATIONS_AUTHORIZE_ASSIGNED,
                PermissionCode::EXCESS_BALANCES_VIEW_ASSIGNED, PermissionCode::REFUNDS_VIEW_ASSIGNED,
                PermissionCode::PAYMENT_EVIDENCE_VIEW,
                PermissionCode::POINTS_VIEW_ASSIGNED,
                PermissionCode::RISK_VIEW_ASSIGNED, PermissionCode::DELINQUENCY_REMOVAL_PREPARE,
                PermissionCode::MOBILITY_VIEW_ASSIGNED,
                PermissionCode::MOBILITY_TRANSFER_AUTHORIZE_ASSIGNED,
                PermissionCode::REPORTS_VIEW_ASSIGNED,
            ],
            RoleCode::VERIFIER->value => [
                ...$own, PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED,
                PermissionCode::ONBOARDING_VERIFICATIONS_PERFORM, PermissionCode::ONBOARDING_EVIDENCE_VIEW,
            ],
            RoleCode::ADMINISTRATOR->value => [
                ...$own, PermissionCode::SECURITY_ALERTS_GLOBAL_READ, PermissionCode::SECURITY_AUDIT_GLOBAL_READ,
                PermissionCode::ONBOARDING_APPLICATIONS_VIEW_GLOBAL, PermissionCode::ONBOARDING_EVIDENCE_VIEW,
                PermissionCode::ONBOARDING_HISTORY_VIEW, PermissionCode::CLIENTS_VIEW_GLOBAL,
                PermissionCode::CONFIGURATION_VIEW_CURRENT, PermissionCode::CONFIGURATION_VIEW_HISTORY,
                PermissionCode::CATEGORY_VIEW, PermissionCode::PRODUCT_VIEW,
                PermissionCode::REDEMPTION_PERIOD_VIEW, PermissionCode::VOUCHERS_VIEW,
                PermissionCode::VOUCHER_MODIFICATIONS_VIEW,
                PermissionCode::PAYMENTS_VIEW_GLOBAL, PermissionCode::EXCESS_BALANCES_VIEW_GLOBAL,
                PermissionCode::REFUNDS_VIEW_GLOBAL,
                PermissionCode::POINTS_VIEW_GLOBAL, PermissionCode::POINTS_RUNS_VIEW_GLOBAL,
                PermissionCode::RISK_VIEW_GLOBAL,
                PermissionCode::MOBILITY_VIEW_GLOBAL,
                PermissionCode::REPORTS_VIEW_GLOBAL,
            ],
            RoleCode::DISTRIBUTOR->value => [
                ...$own, PermissionCode::CLIENTS_VIEW_ASSIGNED, PermissionCode::CLIENTS_CREATE_OWN,
                PermissionCode::CLIENTS_VIEW_SENSITIVE_AUTHORIZED,
                PermissionCode::CLIENTS_VIEW_DOCUMENTS_AUTHORIZED,
                PermissionCode::CLIENTS_PORTFOLIO_VIEW_OWN, PermissionCode::CLIENTS_PORTFOLIO_WRITE_OWN,
                PermissionCode::PRODUCT_VIEW, PermissionCode::REDEMPTION_PERIOD_VIEW,
                PermissionCode::VOUCHERS_GENERATE, PermissionCode::VOUCHERS_VIEW,
                PermissionCode::PAYMENTS_VIEW_OWN, PermissionCode::CLARIFICATIONS_CREATE_OWN,
                PermissionCode::EXCESS_BALANCES_VIEW_OWN, PermissionCode::REFUNDS_VIEW_OWN,
                PermissionCode::EXCESS_BALANCES_DECIDE_OWN, PermissionCode::PAYMENT_EVIDENCE_VIEW,
                PermissionCode::POINTS_VIEW_OWN,
                PermissionCode::RISK_VIEW_OWN,
                PermissionCode::MOBILITY_VIEW_OWN, PermissionCode::MOBILITY_TRANSFER_CREATE_OWN,
                PermissionCode::MOBILITY_TRANSFER_RESPOND_OWN,
                PermissionCode::REPORTS_VIEW_OWN,
            ],
            RoleCode::CASHIER->value => [
                ...$own, PermissionCode::CLIENTS_VIEW_SENSITIVE_AUTHORIZED,
                PermissionCode::CLIENTS_VIEW_DOCUMENTS_AUTHORIZED,
                PermissionCode::CLIENTS_APPLY_AUTHORIZED_CHANGE, PermissionCode::VOUCHERS_VIEW,
                PermissionCode::VOUCHERS_OPEN_AT_COUNTER, PermissionCode::VOUCHERS_RELEASE,
                PermissionCode::VOUCHERS_REJECT, PermissionCode::VOUCHERS_FULFILL,
                PermissionCode::VOUCHER_MODIFICATIONS_REQUEST, PermissionCode::VOUCHER_MODIFICATIONS_APPLY,
                PermissionCode::PAYMENTS_VIEW_BRANCH, PermissionCode::BANK_IMPORTS_UPLOAD,
                PermissionCode::BANK_IMPORTS_RETRY_BRANCH, PermissionCode::CLARIFICATIONS_REVIEW_BRANCH,
                PermissionCode::MANUAL_RECONCILIATIONS_REQUEST,
                PermissionCode::MANUAL_RECONCILIATIONS_APPLY, PermissionCode::EXCESS_BALANCES_VIEW_BRANCH,
                PermissionCode::REFUNDS_VIEW_BRANCH, PermissionCode::REFUNDS_COMPLETE,
                PermissionCode::REFUND_EVIDENCE_VIEW,
                PermissionCode::PAYMENT_EVIDENCE_VIEW,
                PermissionCode::RISK_BLOCK_VIEW_BRANCH,
                PermissionCode::MOBILITY_ASSIGNMENT_VIEW_BRANCH,
            ],
        ];

        foreach ($matrix as $roleCode => $permissionCodes) {
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
            $ids = Permission::query()->whereIn('code', array_map(fn (PermissionCode $code) => $code->value, $permissionCodes))->pluck('id');
            $role->permissions()->syncWithoutDetaching($ids);
        }
    }
}
