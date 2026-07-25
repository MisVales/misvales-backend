<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Integrations;

use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;
use App\Modules\DistributorOnboarding\Domain\Contracts\ReauthenticationPort;
use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Consume una autorización de reautenticación de M01 ligada a la solicitud. */
final class AccessReauthenticationPort implements ReauthenticationPort
{
    public function consume(int $userId, string $applicationPublicId, string $plainToken): void
    {
        $authorization = ReauthAuthorization::query()
            ->where('user_id', $userId)
            ->where('action', 'onboarding.applications.manager_decision')
            ->where('record_type', 'distributor_application')
            ->where('record_id', $applicationPublicId)
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->lockForUpdate()
            ->first();

        if ($authorization === null) {
            throw OnboardingDomainException::reauthenticationRequired();
        }

        $authorization->forceFill(['used_at' => now()])->save();
    }
}
