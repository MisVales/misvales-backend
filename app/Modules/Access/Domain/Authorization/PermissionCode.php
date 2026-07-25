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
}
