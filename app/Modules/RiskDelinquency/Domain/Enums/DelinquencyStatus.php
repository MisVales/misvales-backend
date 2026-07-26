<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum DelinquencyStatus: string
{
    case NOT_DELINQUENT = 'NOT_DELINQUENT';
    case DELINQUENT = 'DELINQUENT';
    case REGULARIZED_PENDING_REMOVAL = 'REGULARIZED_PENDING_REMOVAL';

    public function blocksVoucherIssuance(): bool
    {
        return $this !== self::NOT_DELINQUENT;
    }
}
