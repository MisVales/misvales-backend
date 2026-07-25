<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Puerto de M18 para validar y autorizar evidencias privadas. */
interface MediaPort
{
    public function assertReady(string $mediaId, string $entityPublicId, int $actorUserId): void;
}
