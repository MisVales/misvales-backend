<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum RedemptionPeriodStatus: string
{
    case DRAFT = 'DRAFT';
    case PUBLISHED = 'PUBLISHED';
    case CLOSED = 'CLOSED';
}
