<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Domain\Contracts\DistributorPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\DistributorProvisionResult;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Deniega la creación del perfil hasta que M05 implemente el contrato. */
final class UnavailableDistributorPort implements DistributorPort
{
    public function provision(string $applicationPublicId, string $name, int $branchId, string $operationKey): DistributorProvisionResult
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ACTIVATION_CONFLICT');
    }
}
