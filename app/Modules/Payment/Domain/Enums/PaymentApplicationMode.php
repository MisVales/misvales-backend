<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Modalidad operativa con la que se confirmó una aplicación de pago. */
enum PaymentApplicationMode: string
{
    case AUTOMATIC = 'AUTOMATIC';
    case MANUAL = 'MANUAL';
    case CREDIT_BALANCE = 'CREDIT_BALANCE';
}
