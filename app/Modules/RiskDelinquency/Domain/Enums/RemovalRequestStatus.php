<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum RemovalRequestStatus: string
{
    case PREPARED = 'PREPARED';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case INVALIDATED = 'INVALIDATED';
}
