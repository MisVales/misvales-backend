<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Enums;

enum IncreaseOriginType: string
{
    case NORMAL = 'NORMAL';
    case INSUFFICIENT_CREDIT = 'INSUFFICIENT_CREDIT';
}
