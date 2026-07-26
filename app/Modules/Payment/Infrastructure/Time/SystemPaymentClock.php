<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Time;

use App\Modules\Payment\Application\Contracts\PaymentClock;
use Carbon\CarbonImmutable;

/** Reloj de sistema configurado con la zona horaria de la aplicación. */
final class SystemPaymentClock implements PaymentClock
{
    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }
}
