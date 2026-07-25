<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Hecho confirmado: se desactivó un periodo de canje.
 */
final readonly class RedemptionPeriodDeactivated
{
    use Dispatchable;

    public function __construct(
        public string $periodId,
        public string $deactivatedBy,
        public string $reason,
        public string $occurredAt,
    ) {}
}
