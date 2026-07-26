<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum PointLedgerType: string
{
    case EARNED = 'EARNED';
    case LATE_PAYMENT_PENALTY = 'LATE_PAYMENT_PENALTY';
    case REDEEMED = 'REDEEMED';
}
