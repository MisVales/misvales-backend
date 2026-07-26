<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Enums;

enum DataChangeOperation: string
{
    case CLIENT_DATA_CORRECTION = 'CLIENT_DATA_CORRECTION';
}
