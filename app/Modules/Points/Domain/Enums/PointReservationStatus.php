<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum PointReservationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RELEASED = 'RELEASED';
    case CONSUMED = 'CONSUMED';
}
