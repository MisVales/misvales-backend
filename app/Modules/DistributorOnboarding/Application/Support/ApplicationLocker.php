<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\DistributorApplication;

/** Obtiene la raíz con bloqueo de fila y valida su versión optimista. */
final class ApplicationLocker
{
    public function lock(string $publicId, int $expectedVersion): DistributorApplication
    {
        $application = DistributorApplication::query()
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->first();

        if ($application === null) {
            throw OnboardingDomainException::scopeDenied();
        }

        if ($application->lock_version !== $expectedVersion) {
            throw OnboardingDomainException::versionConflict();
        }

        return $application;
    }
}
