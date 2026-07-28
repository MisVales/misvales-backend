<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum VoucherType: string
{
    case PREVALE = 'PREVALE';
    case DIGITAL = 'VALE_DIGITAL';
}
