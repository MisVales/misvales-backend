<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Domain\Contracts\OrganizationPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\ResponsibleContext;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Deniega operaciones dependientes de M02 hasta que exista su adaptador propietario. */
final class UnavailableOrganizationPort implements OrganizationPort
{
    public function resolveCreationContext(ActorContext $actor): ResponsibleContext
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ORGANIZATION_PROVISION_FAILED');
    }

    public function assertResponsibleCoordinator(int $coordinatorUserId, int $branchId): void
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ORGANIZATION_PROVISION_FAILED');
    }

    public function assertVerifier(int $verifierUserId, int $branchId): void
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ORGANIZATION_PROVISION_FAILED');
    }

    public function createDistributorAssignment(string $distributorId, int $coordinatorUserId, int $branchId, string $operationKey): string
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ORGANIZATION_PROVISION_FAILED');
    }
}
