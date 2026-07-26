<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;

/** Puerto cerrado hasta que se defina el rol ejecutor y el contrato de entrega. */
final class CompletePointRedemption
{
    /** @param array<string, mixed> $delivery */
    public function execute(User $actor, string $requestId, array $delivery, string $idempotencyKey): never
    {
        throw new PointsDomainException(
            'POINT_DELIVERY_ROLE_UNDEFINED',
            'El registro de entrega permanece cerrado hasta definir el rol ejecutor y sus campos obligatorios.',
            403,
        );
    }
}
