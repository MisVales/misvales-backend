<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

/**
 * DTO para desactivar un periodo de canje.
 */
final readonly class DeactivateRedemptionPeriodData
{
    public function __construct(
        public string $periodPublicId,
        public string $reason,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
