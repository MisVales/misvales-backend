<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum PointLedgerDirection: string
{
    case CREDIT = 'CREDIT';
    case DEBIT = 'DEBIT';
}
