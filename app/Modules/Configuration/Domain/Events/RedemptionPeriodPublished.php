<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se publicó un periodo de canje.
 */
final readonly class RedemptionPeriodPublished
{
    use Dispatchable;

    public function __construct(
        public string $periodId,
        public string $startsAt,
        public string $endsAt,
        public string $publishedBy,
        public string $occurredAt,
    ) {}
}
