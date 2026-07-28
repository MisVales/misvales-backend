<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\DTOs;

use App\Modules\Voucher\Domain\ValueObjects\Money;

final readonly class VoucherCalculation
{
    /**
     * @param  list<array<string, Money|int>>  $installments
     */
    public function __construct(
        public Money $capital,
        public Money $loanCommission,
        public Money $totalInterest,
        public Money $insurance,
        public Money $misvalesTotal,
        public Money $distributorProfit,
        public Money $clientTotal,
        public Money $baseInstallment,
        public Money $profitInstallment,
        public Money $clientInstallment,
        public int $payments,
        public array $installments,
    ) {}
}
