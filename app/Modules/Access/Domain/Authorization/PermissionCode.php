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
}
