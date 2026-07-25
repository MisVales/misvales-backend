<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Portfolio;

/** Movimientos exclusivamente informativos admitidos por la cartera M06. */
enum PortfolioEntryType: string
{
    case VOUCHER = 'VOUCHER';
    case PAYMENT = 'PAYMENT';
    case INSTALLMENT = 'INSTALLMENT';
    case STATUS_UPDATE = 'STATUS_UPDATE';
    case NOTE = 'NOTE';

    public function carriesAmount(): bool
    {
        return in_array($this, [self::VOUCHER, self::PAYMENT, self::INSTALLMENT], true);
    }

    public function isDistributorWritable(): bool
    {
        return $this !== self::VOUCHER;
    }
}
