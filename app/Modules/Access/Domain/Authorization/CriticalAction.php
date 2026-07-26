<?php

namespace App\Modules\Access\Domain\Authorization;

enum CriticalAction: string
{
    case ACCOUNT_CREATE = 'accounts.create';
    case ACCOUNT_REQUEST_CREATE = 'account_requests.create';
    case ACCOUNT_REQUEST_APPROVE = 'account_requests.approve';
    case ACCOUNT_REQUEST_REJECT = 'account_requests.reject';
    case ACCOUNT_DISABLE = 'accounts.disable';
    case ACCOUNT_REACTIVATE = 'accounts.reactivate';
    case ACCOUNT_RECOVERY = 'accounts.recovery';
    case ACCOUNT_INVITATION_RESEND = 'accounts.invitation.resend';
    case PASSWORD_CHANGE = 'password.change';
    case MFA_TOTP_ADD = 'mfa.totp.add';
    case MFA_TOTP_REMOVE = 'mfa.totp.remove';
    case MFA_PASSKEY_ADD = 'mfa.passkey.add';
    case MFA_PASSKEY_REMOVE = 'mfa.passkey.remove';
    case MFA_RECOVERY_CODES_REGENERATE = 'mfa.recovery_codes.regenerate';
    case SESSION_REVOKE = 'sessions.revoke';
    case SESSION_REVOKE_OTHERS = 'sessions.revoke_others';
    case PERMISSIONS_CHANGE = 'access.permissions.change';
    case BRANCH_CHANGE = 'access.branches.change';
    case HIERARCHY_CHANGE = 'access.hierarchy.change';
    case ASSIGNMENT_CHANGE = 'access.assignments.change';
    case FINANCIAL_ADJUSTMENT = 'financial.adjustment';
    case PAYMENT_CANCEL = 'financial.payment.cancel';
    case FOLIO_REOPEN = 'financial.folio.reopen';
    case CASH_CLOSE = 'financial.cash.close';
    case DELIVERY_CONFIRM = 'financial.delivery.confirm';
    case CREDIT_INCREASE_DECISION = 'credit.increase.decision';
    case OPERATIONAL_AUTHORIZE = 'operational.authorize';

    // M03 — Configuraciones y catálogos
    case CONFIGURATION_VERSION_CREATE = 'configuration.version.create';
    case CONFIGURATION_VERSION_EDIT = 'configuration.version.edit';
    case CONFIGURATION_VERSION_PUBLISH = 'configuration.version.publish';
    case CONFIGURATION_VERSION_DEACTIVATE = 'configuration.version.deactivate';
    case CATEGORY_CREATE = 'configuration.category.create';
    case CATEGORY_VERSION_CREATE = 'configuration.category.version.create';
    case CATEGORY_VERSION_EDIT = 'configuration.category.version.edit';
    case CATEGORY_VERSION_PUBLISH = 'configuration.category.version.publish';
    case CATEGORY_DEACTIVATE = 'configuration.category.deactivate';
    case PRODUCT_CREATE = 'configuration.product.create';
    case PRODUCT_VERSION_CREATE = 'configuration.product.version.create';
    case PRODUCT_VERSION_EDIT = 'configuration.product.version.edit';
    case PRODUCT_VERSION_PUBLISH = 'configuration.product.version.publish';
    case PRODUCT_DEACTIVATE = 'configuration.product.deactivate';
    case REDEMPTION_PERIOD_CREATE = 'configuration.redemption_period.create';
    case REDEMPTION_PERIOD_EDIT = 'configuration.redemption_period.edit';
    case REDEMPTION_PERIOD_PUBLISH = 'configuration.redemption_period.publish';
    case REDEMPTION_PERIOD_DEACTIVATE = 'configuration.redemption_period.deactivate';
    case VOUCHER_MODIFICATION_DECIDE = 'voucher.modification.decide';
    case MANUAL_RECONCILIATION_DECIDE = 'payment.manual_reconciliation.decide';
    case EXCESS_REFUND_DECIDE = 'payment.excess_refund.decide';
    case POINT_REDEMPTION_AUTHORIZE = 'points.redemption.authorize';
    case POINT_REDEMPTION_REJECT = 'points.redemption.reject';
    case DELINQUENCY_APPLY = 'risk.delinquency.apply';
    case DELINQUENCY_REMOVE = 'risk.delinquency.remove';
    case MOBILITY_ADMINISTRATIVE_REASSIGNMENT = 'mobility.administrative_reassignment';
    case MOBILITY_BRANCH_CHANGE = 'mobility.branch_change';
    case MOBILITY_COORDINATOR_REASSIGNMENT = 'mobility.coordinator_reassignment';
}
