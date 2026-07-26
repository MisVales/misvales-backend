<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Enums;

enum ExcessBalanceBucket: string
{
    case RETAINED = 'RETAINED';
    case AVAILABLE = 'AVAILABLE';
    case RESERVED = 'RESERVED';
    case APPLIED = 'APPLIED';
    case REFUNDED = 'REFUNDED';
}
