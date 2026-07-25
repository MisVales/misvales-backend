<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Domain\Contracts\DifferenceCatalogPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Rechaza clasificaciones hasta que el catálogo exacto sea aprobado. */
final class UnavailableDifferenceCatalog implements DifferenceCatalogPort
{
    public function assertApproved(string $classificationCode): void
    {
        throw OnboardingDomainException::incomplete();
    }
}
