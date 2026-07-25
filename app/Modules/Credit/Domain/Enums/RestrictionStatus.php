<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Enums;

enum RestrictionStatus: string
{
    case ACTIVE = 'ACTIVE';
    case BOUND = 'BOUND';
    case CONSUMED = 'CONSUMED';
}
