<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Contracts;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\CriticalAction;

interface MobilityReauthenticationPort
{
    public function consume(
        User $actor,
        string $token,
        CriticalAction $action,
        string $resourceType,
        string $resourceId,
    ): int;
}
