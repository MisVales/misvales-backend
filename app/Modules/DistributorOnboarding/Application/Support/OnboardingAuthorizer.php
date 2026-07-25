<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Aplica permisos, alcance, asignación y separación de funciones en backend. */
final class OnboardingAuthorizer
{
    public function assertPermission(ActorContext $actor, PermissionCode $permission): void
    {
        if (! $actor->hasPermission($permission->value)) {
            throw OnboardingDomainException::authorizationDenied();
        }
    }

    public function assertCanView(ActorContext $actor, DistributorApplication $application): void
    {
        if ($actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_GLOBAL->value)) {
            return;
        }

        if (
            $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_BRANCH->value)
            && $actor->branchId === (int) $application->branch_id
        ) {
            return;
        }

        if (
            $actor->role === RoleCode::COORDINATOR
            && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED->value)
            && $actor->userId === (int) $application->coordinator_user_id
        ) {
            return;
        }

        if (
            $actor->role === RoleCode::VERIFIER
            && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_VIEW_ASSIGNED->value)
            && $application->activeVerifierAssignment()
                ->where('verifier_user_id', $actor->userId)
                ->exists()
        ) {
            return;
        }

        throw OnboardingDomainException::scopeDenied();
    }

    public function assertResponsibleCoordinator(
        ActorContext $actor,
        DistributorApplication $application,
        PermissionCode $permission,
    ): void {
        $this->assertPermission($actor, $permission);

        if (
            $actor->role !== RoleCode::COORDINATOR
            || $actor->userId !== (int) $application->coordinator_user_id
            || $actor->branchId !== (int) $application->branch_id
        ) {
            throw OnboardingDomainException::scopeDenied();
        }
    }

    public function assertAssignedVerifier(ActorContext $actor, DistributorApplication $application): void
    {
        $this->assertPermission($actor, PermissionCode::ONBOARDING_VERIFICATIONS_PERFORM);

        if (
            $actor->role !== RoleCode::VERIFIER
            || $actor->branchId !== (int) $application->branch_id
            || ! $application->activeVerifierAssignment()
                ->where('verifier_user_id', $actor->userId)
                ->exists()
        ) {
            throw OnboardingDomainException::scopeDenied();
        }
    }

    public function assertManagerDecision(ActorContext $actor, DistributorApplication $application): void
    {
        if (
            $actor->role === RoleCode::GENERAL_MANAGER
            && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_AUTHORIZE_GLOBAL->value)
        ) {
            return;
        }

        if (
            $actor->role === RoleCode::SUCURSAL_MANAGER
            && $actor->hasPermission(PermissionCode::ONBOARDING_APPLICATIONS_AUTHORIZE_BRANCH->value)
            && $actor->branchId === (int) $application->branch_id
        ) {
            return;
        }

        throw OnboardingDomainException::scopeDenied();
    }
}
