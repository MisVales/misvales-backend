<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Domain\Contracts\ExpedientRequirementsPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Mantiene el envío cerrado hasta que se apruebe la matriz funcional. */
final class UnavailableExpedientRequirements implements ExpedientRequirementsPort
{
    public function assertComplete(DistributorApplication $application): void
    {
        throw OnboardingDomainException::incomplete();
    }
}
