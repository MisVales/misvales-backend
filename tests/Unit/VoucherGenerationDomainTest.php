<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Voucher\Domain\Services\InstallmentAllocator;
use App\Modules\Voucher\Domain\Services\VoucherCalculator;
use App\Modules\Voucher\Domain\ValueObjects\Money;
use App\Modules\Voucher\Domain\ValueObjects\Percentage;
use PHPUnit\Framework\TestCase;

final class VoucherGenerationDomainTest extends TestCase
{
    public function test_financial_formula_and_installment_residue_are_exact(): void
    {
        $calculation = (new VoucherCalculator(new InstallmentAllocator))->calculate(
            new Money('15000.0000'),
            new Percentage('0.1000'),
            new Percentage('0.0500'),
            8,
            new Money('100.0000'),
            new Percentage('0.0600'),
        );

        self::assertSame('1500.00', $calculation->loanCommission->format());
        self::assertSame('6000.00', $calculation->totalInterest->format());
        self::assertSame('22600.00', $calculation->misvalesTotal->format());
        self::assertSame('900.00', $calculation->distributorProfit->format());
        self::assertSame('23500.00', $calculation->clientTotal->format());
        self::assertCount(8, $calculation->installments);

        foreach (['capital', 'loan_commission', 'interest', 'insurance', 'distributor_profit'] as $component) {
            $sum = Money::zero();
            foreach ($calculation->installments as $installment) {
                $sum = $sum->add($installment[$component]);
            }
            $expected = match ($component) {
                'capital' => $calculation->capital,
                'loan_commission' => $calculation->loanCommission,
                'interest' => $calculation->totalInterest,
                'insurance' => $calculation->insurance,
                'distributor_profit' => $calculation->distributorProfit,
            };
            self::assertSame($expected->databaseValue(), $sum->databaseValue());
        }
    }

    public function test_half_up_rounding_and_four_decimal_internal_precision(): void
    {
        self::assertSame('10.01', (new Money('10.0050'))->format());
        self::assertSame('3.3333', (new Money('10.0000'))->divide(3)->databaseValue());
    }
}
