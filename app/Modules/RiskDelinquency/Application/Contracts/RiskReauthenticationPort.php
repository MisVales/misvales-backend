<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\CriticalAction;

interface RiskReauthenticationPort
{
    /** @param array<string, mixed> $parameters */
    public function consume(
        User $actor,
        string $plainToken,
        CriticalAction $action,
        string $resourceType,
        string $resourceId,
        ?string $branchPublicId,
        array $parameters,
    ): int;
}
