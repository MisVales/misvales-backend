<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\DTOs;

use Carbon\CarbonImmutable;

/**
 * DTO para editar un borrador de periodo de canje.
 */
final readonly class EditRedemptionPeriodData
{
    public function __construct(
        public string $periodPublicId,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $lockVersion,
        public int $actorUserId,
    ) {}
}
