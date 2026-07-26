<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Application\Accounts\TemporaryAuthorization;
use App\Modules\Access\Domain\Authorization\AuthorizationBinding;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Mobility\Application\Contracts\MobilityReauthenticationPort;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use Throwable;

final readonly class AccessMobilityReauthentication implements MobilityReauthenticationPort
{
    public function __construct(private TemporaryAuthorization $authorizations) {}

    public function consume(
        User $actor,
        string $token,
        CriticalAction $action,
        string $resourceType,
        string $resourceId,
    ): int {
        try {
            return $this->authorizations->consume($actor, $token, new AuthorizationBinding(
                action: $action,
                resourceType: $resourceType,
                resourceId: $resourceId,
                branchId: $actor->branch_public_id,
                parameters: [],
            ))->id;
        } catch (Throwable) {
            throw MobilityException::reauthenticationRequired();
        }
    }
}
