<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\Enums;

enum CoordinatorReassignmentStatus: string
{
    case REGISTERED = 'REGISTERED';
    case ASSIGNMENT_PENDING = 'ASSIGNMENT_PENDING';
    case READY_TO_COMPLETE = 'READY_TO_COMPLETE';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
