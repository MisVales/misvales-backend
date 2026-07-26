<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;

/**
 * Contrato deliberadamente cerrado.
 *
 * Las fuentes no definen canje total/parcial, mínimos ni máximos. Reservar una
 * cantidad sería inventar una regla financiera, por lo que producción deniega.
 */
final class RequestPointRedemption
{
    public function execute(User $distributor, int $requestedPoints, string $idempotencyKey): never
    {
        throw new PointsDomainException(
            'REDEMPTION_POLICY_UNDEFINED',
            'La solicitud de canje permanece cerrada hasta definir la cantidad canjeable.',
            409,
        );
    }
}
