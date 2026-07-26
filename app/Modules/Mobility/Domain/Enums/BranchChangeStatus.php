<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\Enums;

enum BranchChangeStatus: string
{
    case REQUESTED = 'REQUESTED';
    case AUTHORIZED = 'AUTHORIZED';
    case CLIENT_REASSIGNMENT_PENDING = 'CLIENT_REASSIGNMENT_PENDING';
    case DESTINATION_COORDINATOR_PENDING = 'DESTINATION_COORDINATOR_PENDING';
    case READY_TO_COMPLETE = 'READY_TO_COMPLETE';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
}
