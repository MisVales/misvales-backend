<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Puerto de M01 para consumir una reautenticación ligada a la decisión final. */
interface ReauthenticationPort
{
    public function consume(int $userId, string $applicationPublicId, string $plainToken): void;
}
