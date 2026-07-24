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
    case OPERATIONAL_AUTHORIZE = 'operational.authorize';
}
