<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Models\User;
use App\Modules\DistributorOnboarding\Domain\Contracts\AccountPort;
use App\Modules\DistributorOnboarding\Domain\Contracts\AccountProvisionResult;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Valida unicidad existente en M01 y deniega el aprovisionamiento aún no implementado por su propietario. */
final class UnavailableAccountPort implements AccountPort
{
    public function assertEmailAvailable(string $normalizedEmail): void
    {
        if (User::query()->where('normalized_email', $normalizedEmail)->exists()) {
            throw OnboardingDomainException::emailAlreadyUsed();
        }
    }

    public function provisionDistributor(string $normalizedEmail, string $name, int $branchId, string $operationKey): AccountProvisionResult
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_ACCOUNT_PROVISION_FAILED');
    }
}
