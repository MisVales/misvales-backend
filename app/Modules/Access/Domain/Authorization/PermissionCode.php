<?php

namespace App\Modules\Access\Domain\Authorization;

enum PermissionCode: string
{
    case CONTEXT_READ = 'auth.context.read';
    case SESSIONS_READ_OWN = 'auth.sessions.read_own';
    case SESSIONS_REVOKE_OWN = 'auth.sessions.revoke_own';
    case PASSWORD_CHANGE_OWN = 'auth.password.change_own';
    case MFA_MANAGE_OWN = 'auth.mfa.manage_own';
    case ACCOUNTS_GLOBAL_CREATE = 'accounts.global.create';
    case ACCOUNTS_BRANCH_REQUEST = 'accounts.branch.request';
    case ACCOUNTS_GLOBAL_APPROVE = 'accounts.global.approve';
    case ACCOUNTS_GLOBAL_DISABLE = 'accounts.global.disable';
    case ACCOUNTS_BRANCH_DISABLE_REQUEST = 'accounts.branch.disable_request';
    case SECURITY_ALERTS_GLOBAL_READ = 'security.alerts.global.read';
    case SECURITY_ALERTS_BRANCH_READ = 'security.alerts.branch.read';
    case SECURITY_AUDIT_GLOBAL_READ = 'security.audit.global.read';
    case ONBOARDING_APPLICATIONS_CREATE = 'onboarding.applications.create';
    case ONBOARDING_APPLICATIONS_UPDATE_CAPTURE = 'onboarding.applications.update_capture';
    case ONBOARDING_APPLICATIONS_SUBMIT = 'onboarding.applications.submit';
    case ONBOARDING_APPLICATIONS_VIEW_ASSIGNED = 'onboarding.applications.view_assigned';
    case ONBOARDING_APPLICATIONS_VIEW_BRANCH = 'onboarding.applications.view_branch';
    case ONBOARDING_APPLICATIONS_VIEW_GLOBAL = 'onboarding.applications.view_global';
    case ONBOARDING_APPLICATIONS_REVIEW = 'onboarding.applications.review';
    case ONBOARDING_VERIFICATIONS_ASSIGN = 'onboarding.verifications.assign';
    case ONBOARDING_VERIFICATIONS_PERFORM = 'onboarding.verifications.perform';
    case ONBOARDING_APPLICATIONS_CORRECT = 'onboarding.applications.correct';
    case ONBOARDING_APPLICATIONS_EVALUATE = 'onboarding.applications.evaluate';
    case ONBOARDING_APPLICATIONS_AUTHORIZE_BRANCH = 'onboarding.applications.authorize_branch';
    case ONBOARDING_APPLICATIONS_AUTHORIZE_GLOBAL = 'onboarding.applications.authorize_global';
    case ONBOARDING_EVIDENCE_VIEW = 'onboarding.evidence.view';
    case ONBOARDING_HISTORY_VIEW = 'onboarding.history.view';
    case CLIENTS_VIEW_GLOBAL = 'clients.view.global';
    case CLIENTS_VIEW_BRANCH = 'clients.view.branch';
    case CLIENTS_VIEW_ASSIGNED = 'clients.view.assigned';
    case CLIENTS_CREATE_OWN = 'clients.create.own';
    case CLIENTS_VIEW_SENSITIVE_AUTHORIZED = 'clients.view_sensitive.authorized';
    case CLIENTS_VIEW_DOCUMENTS_AUTHORIZED = 'clients.view_documents.authorized';
    case CLIENTS_APPLY_AUTHORIZED_CHANGE = 'clients.apply_authorized_change';
    case CLIENTS_PORTFOLIO_VIEW_OWN = 'clients.portfolio.view.own';
    case CLIENTS_PORTFOLIO_WRITE_OWN = 'clients.portfolio.write.own';
    case CLIENTS_ASSIGNMENT_APPLY_INTERNAL = 'clients.assignment.apply_internal';

    // M03 — Configuraciones y catálogos
    case CONFIGURATION_VIEW_CURRENT = 'configuration.view.current';
    case CONFIGURATION_VIEW_HISTORY = 'configuration.view.history';
    case CONFIGURATION_MANAGE = 'configuration.manage';
    case CONFIGURATION_PUBLISH = 'configuration.publish';
    case CATEGORY_VIEW = 'configuration.category.view';
    case CATEGORY_MANAGE = 'configuration.category.manage';
    case CATEGORY_PUBLISH = 'configuration.category.publish';
    case PRODUCT_VIEW = 'configuration.product.view';
    case PRODUCT_MANAGE = 'configuration.product.manage';
    case PRODUCT_PUBLISH = 'configuration.product.publish';
    case REDEMPTION_PERIOD_VIEW = 'configuration.redemption_period.view';
    case REDEMPTION_PERIOD_MANAGE = 'configuration.redemption_period.manage';

    // M08 — Generación y consulta de vales
    case VOUCHERS_GENERATE = 'vouchers.generate';
    case VOUCHERS_VIEW = 'vouchers.view';

    // M09 — Caja, modificaciones autorizadas y feriado
    case VOUCHERS_OPEN_AT_COUNTER = 'vouchers.open_at_counter';
    case VOUCHERS_RELEASE = 'vouchers.release';
    case VOUCHERS_REJECT = 'vouchers.reject';
    case VOUCHERS_FULFILL = 'vouchers.fulfill';
    case VOUCHER_MODIFICATIONS_REQUEST = 'voucher_modifications.request';
    case VOUCHER_MODIFICATIONS_APPLY = 'voucher_modifications.apply';
    case VOUCHER_MODIFICATIONS_VIEW = 'voucher_modifications.view';
    case VOUCHER_MODIFICATIONS_DECIDE = 'voucher_modifications.decide';

    // M11 — Conciliación, pagos y recuperación de línea
    case PAYMENTS_VIEW_OWN = 'payments.view.own';
    case PAYMENTS_VIEW_BRANCH = 'payments.view.branch';
    case PAYMENTS_VIEW_ASSIGNED = 'payments.view.assigned';
    case PAYMENTS_VIEW_GLOBAL = 'payments.view.global';
    case BANK_IMPORTS_UPLOAD = 'bank_imports.upload';
    case BANK_IMPORTS_RETRY_BRANCH = 'bank_imports.retry.branch';
    case BANK_IMPORTS_RETRY_GLOBAL = 'bank_imports.retry.global';
    case CLARIFICATIONS_CREATE_OWN = 'clarifications.create.own';
    case CLARIFICATIONS_REVIEW_BRANCH = 'clarifications.review.branch';
    case MANUAL_RECONCILIATIONS_REQUEST = 'manual_reconciliations.request';
    case MANUAL_RECONCILIATIONS_AUTHORIZE_ASSIGNED = 'manual_reconciliations.authorize.assigned';
    case MANUAL_RECONCILIATIONS_AUTHORIZE_BRANCH = 'manual_reconciliations.authorize.branch';
    case MANUAL_RECONCILIATIONS_AUTHORIZE_GLOBAL = 'manual_reconciliations.authorize.global';
    case MANUAL_RECONCILIATIONS_APPLY = 'manual_reconciliations.apply';
    case EXCESS_BALANCES_DECIDE_OWN = 'excess_balances.decide.own';
    case EXCESS_BALANCES_VIEW_OWN = 'excess_balances.view.own';
    case EXCESS_BALANCES_VIEW_BRANCH = 'excess_balances.view.branch';
    case EXCESS_BALANCES_VIEW_ASSIGNED = 'excess_balances.view.assigned';
    case EXCESS_BALANCES_VIEW_GLOBAL = 'excess_balances.view.global';
    case REFUNDS_VIEW_OWN = 'refunds.view.own';
    case REFUNDS_VIEW_BRANCH = 'refunds.view.branch';
    case REFUNDS_VIEW_ASSIGNED = 'refunds.view.assigned';
    case REFUNDS_VIEW_GLOBAL = 'refunds.view.global';
    case REFUNDS_AUTHORIZE_BRANCH = 'refunds.authorize.branch';
    case REFUNDS_AUTHORIZE_GLOBAL = 'refunds.authorize.global';
    case REFUNDS_COMPLETE = 'refunds.complete';
    case REFUND_EVIDENCE_VIEW = 'refunds.evidence.view';
    case PAYMENT_EVIDENCE_VIEW = 'payment_evidence.view';

    // M13 — Puntos y canjes
    case POINTS_VIEW_OWN = 'points.view.own';
    case POINTS_VIEW_BRANCH = 'points.view.branch';
    case POINTS_VIEW_ASSIGNED = 'points.view.assigned';
    case POINTS_VIEW_GLOBAL = 'points.view.global';
    case POINT_REDEMPTIONS_DECIDE_BRANCH = 'points.redemptions.decide.branch';
    case POINT_REDEMPTIONS_DECIDE_GLOBAL = 'points.redemptions.decide.global';
    case POINTS_RUNS_VIEW_GLOBAL = 'points.runs.view.global';

    // M14 — Riesgo y morosidad
    case RISK_VIEW_OWN = 'risk.view.own';
    case RISK_VIEW_ASSIGNED = 'risk.view.assigned';
    case RISK_VIEW_BRANCH = 'risk.view.branch';
    case RISK_VIEW_GLOBAL = 'risk.view.global';
    case RISK_BLOCK_VIEW_BRANCH = 'risk.block.view.branch';
    case DELINQUENCY_APPLY_BRANCH = 'delinquency.apply.branch';
    case DELINQUENCY_APPLY_GLOBAL = 'delinquency.apply.global';
    case DELINQUENCY_REMOVAL_PREPARE = 'delinquency.removal.prepare';
    case DELINQUENCY_REMOVAL_DECIDE_BRANCH = 'delinquency.removal.decide.branch';
    case DELINQUENCY_REMOVAL_DECIDE_GLOBAL = 'delinquency.removal.decide.global';

    // M15 — Transferencias y reasignaciones
    case MOBILITY_VIEW_OWN = 'mobility.view.own';
    case MOBILITY_VIEW_ASSIGNED = 'mobility.view.assigned';
    case MOBILITY_VIEW_BRANCH = 'mobility.view.branch';
    case MOBILITY_VIEW_GLOBAL = 'mobility.view.global';
    case MOBILITY_TRANSFER_CREATE_OWN = 'mobility.transfer.create.own';
    case MOBILITY_TRANSFER_RESPOND_OWN = 'mobility.transfer.respond.own';
    case MOBILITY_TRANSFER_AUTHORIZE_ASSIGNED = 'mobility.transfer.authorize.assigned';
    case MOBILITY_REASSIGN_BRANCH = 'mobility.reassign.branch';
    case MOBILITY_REASSIGN_GLOBAL = 'mobility.reassign.global';
    case MOBILITY_BRANCH_CHANGE_BRANCH = 'mobility.branch_change.branch';
    case MOBILITY_BRANCH_CHANGE_GLOBAL = 'mobility.branch_change.global';
    case MOBILITY_COORDINATOR_REASSIGN_BRANCH = 'mobility.coordinator_reassign.branch';
    case MOBILITY_COORDINATOR_REASSIGN_GLOBAL = 'mobility.coordinator_reassign.global';
    case MOBILITY_ASSIGNMENT_VIEW_BRANCH = 'mobility.assignment.view.branch';

    // M16 — Reportes
    case REPORTS_VIEW_OWN = 'reports.view.own';
    case REPORTS_VIEW_ASSIGNED = 'reports.view.assigned';
    case REPORTS_VIEW_BRANCH = 'reports.view.branch';
    case REPORTS_VIEW_GLOBAL = 'reports.view.global';
}
