<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO para publicar un periodo de canje.
 */
final readonly class PublishRedemptionPeriodData
{
    public function __construct(
        public string $periodPublicId,
        public string $reason,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
