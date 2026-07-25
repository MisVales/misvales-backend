<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\DistributorOnboarding\Domain\Contracts\MediaPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Impide presentar archivos como válidos antes de integrar M18. */
final class UnavailableMediaPort implements MediaPort
{
    public function assertReady(string $mediaId, string $entityPublicId, int $actorUserId): void
    {
        throw OnboardingDomainException::integrationUnavailable('APPLICATION_EVIDENCE_NOT_READY');
    }
}
