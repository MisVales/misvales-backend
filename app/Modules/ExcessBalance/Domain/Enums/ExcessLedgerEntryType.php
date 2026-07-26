<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\Enums;

enum ExcessLedgerEntryType: string
{
    case EXCESS_DETECTED = 'EXCESS_DETECTED';
    case MARKED_AS_CREDIT = 'MARKED_AS_CREDIT';
    case RESERVED_FOR_REFUND = 'RESERVED_FOR_REFUND';
    case CREDIT_APPLIED = 'CREDIT_APPLIED';
    case REFUND_COMPLETED = 'REFUND_COMPLETED';
}
