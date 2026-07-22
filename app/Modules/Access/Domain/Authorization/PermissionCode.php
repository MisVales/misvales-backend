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
}
