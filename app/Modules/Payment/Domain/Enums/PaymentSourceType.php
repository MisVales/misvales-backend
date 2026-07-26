<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\Enums;

/** Origen autoritativo de los fondos aplicados. */
enum PaymentSourceType: string
{
    case BANK_MOVEMENT = 'BANK_MOVEMENT';
    case CREDIT_BALANCE = 'CREDIT_BALANCE';
}
