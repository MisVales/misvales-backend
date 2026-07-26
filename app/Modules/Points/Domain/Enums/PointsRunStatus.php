<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum PointsRunStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case COMPLETED_WITH_ERRORS = 'COMPLETED_WITH_ERRORS';
    case FAILED = 'FAILED';
}
