<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Domain\Contracts\ConfigurationPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Evita inventar límites de crédito o hardcodear la tolerancia que debe publicar M03. */
final class UnavailableConfigurationPort implements ConfigurationPort
{
    public function assertInitialCreditLineAllowed(string $amount): void
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ACTIVATION_CONFLICT');
    }

    public function firstVoucherTolerance(): string
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ACTIVATION_CONFLICT');
    }
}
