<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO para crear un periodo de canje.
 */
final readonly class CreateRedemptionPeriodData
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $actorUserId,
        public string $idempotencyKey,
    ) {}
}
