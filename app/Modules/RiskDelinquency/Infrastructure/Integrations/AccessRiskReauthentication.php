<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\RiskDelinquency\Application\Contracts\RiskReauthenticationPort;

final class AccessRiskReauthentication implements RiskReauthenticationPort
{
    public function __construct(private readonly TemporaryAuthorization $authorization) {}

    public function consume(
        User $actor,
        string $plainToken,
        CriticalAction $action,
        string $resourceType,
        string $resourceId,
        ?string $branchPublicId,
        array $parameters,
    ): int {
        return $this->authorization->consume($actor, $plainToken, new AuthorizationBinding(
            action: $action,
            resourceType: $resourceType,
            resourceId: $resourceId,
            branchId: $branchPublicId,
            parameters: $parameters,
        ))->id;
    }
}
