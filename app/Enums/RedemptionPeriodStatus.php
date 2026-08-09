<?php

namespace App\Enums;

enum RedemptionPeriodStatus: string
{
    case DRAFT = 'DRAFT';
    case SCHEDULED = 'SCHEDULED';
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';
}
