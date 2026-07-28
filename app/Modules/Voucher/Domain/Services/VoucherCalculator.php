<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\Services;

use App\Modules\Voucher\Domain\DTOs\VoucherCalculation;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Domain\ValueObjects\Money;
use App\Modules\Voucher\Domain\ValueObjects\Percentage;

final readonly class VoucherCalculator
{
    public function __construct(private InstallmentAllocator $allocator) {}

    public function calculate(
        Money $capital,
        Percentage $commissionRate,
        Percentage $interestRate,
        int $payments,
        Money $insurance,
        Percentage $profitRate,
    ): VoucherCalculation {
        if ($payments < 1 || $insurance->isNegative()) {
            throw VoucherDomainException::productIncomplete();
        }

        $commission = $capital->multiply($commissionRate)->rounded();
        $interest = $capital->multiply($interestRate)->multiply($payments)->rounded();
        $insurance = $insurance->rounded();
        $capital = $capital->rounded();
        $profit = $capital->multiply($profitRate)->rounded();
        $misvalesTotal = $capital->add($commission)->add($interest)->add($insurance);
        $clientTotal = $misvalesTotal->add($profit);

        $components = [
            'capital' => $this->allocator->allocate($capital, $payments),
            'loan_commission' => $this->allocator->allocate($commission, $payments),
            'interest' => $this->allocator->allocate($interest, $payments),
            'insurance' => $this->allocator->allocate($insurance, $payments),
            'distributor_profit' => $this->allocator->allocate($profit, $payments),
        ];
        $installments = [];
        for ($index = 0; $index < $payments; $index++) {
            $base = $components['capital'][$index]
                ->add($components['loan_commission'][$index])
                ->add($components['interest'][$index])
                ->add($components['insurance'][$index]);
            $total = $base->add($components['distributor_profit'][$index]);
            $installments[] = [
                'payment_number' => $index + 1,
                'total_payments' => $payments,
                'capital' => $components['capital'][$index],
                'loan_commission' => $components['loan_commission'][$index],
                'interest' => $components['interest'][$index],
                'insurance' => $components['insurance'][$index],
                'base_payment' => $base,
                'distributor_profit' => $components['distributor_profit'][$index],
                'client_total' => $total,
                'misvales_due' => $base,
            ];
        }

        return new VoucherCalculation(
            $capital,
            $commission,
            $interest,
            $insurance,
            $misvalesTotal,
            $profit,
            $clientTotal,
            $misvalesTotal->divide($payments),
            $profit->divide($payments),
            $clientTotal->divide($payments),
            $payments,
            $installments,
        );
    }
}
