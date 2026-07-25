<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Enums;

enum CreditMovementType: string
{
    case INITIAL_AUTHORIZATION = 'INITIAL_AUTHORIZATION';
    case INCREASE = 'INCREASE';
    case VOUCHER_FULFILLED = 'VOUCHER_FULFILLED';
    case CAPITAL_RECOVERED = 'CAPITAL_RECOVERED';
    case AUTHORIZED_CORRECTION = 'AUTHORIZED_CORRECTION';
}
