<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

use Carbon\CarbonImmutable;

/** Proporciona el instante autoritativo usado por las operaciones de M11. */
interface PaymentClock
{
    public function now(): CarbonImmutable;
}
