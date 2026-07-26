<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\Enums;

enum AdministrativeReassignmentStatus: string
{
    case DRAFT = 'DRAFT';
    case VALIDATED = 'VALIDATED';
    case COMPLETED = 'COMPLETED';
    case REJECTED_BY_VALIDATION = 'REJECTED_BY_VALIDATION';
    case CANCELLED = 'CANCELLED';
}
