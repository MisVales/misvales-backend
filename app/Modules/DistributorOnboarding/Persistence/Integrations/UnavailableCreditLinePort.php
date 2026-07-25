<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Domain\Contracts\CreditLinePort;
use App\Modules\DistributorOnboarding\Domain\Contracts\CreditLineProvisionResult;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Deniega la apertura hasta que M07 implemente el contrato. */
final class UnavailableCreditLinePort implements CreditLinePort
{
    public function openInitialLine(string $distributorId, string $amount, string $tolerance, string $operationKey): CreditLineProvisionResult
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_CREDIT_LINE_PROVISION_FAILED');
    }
}
