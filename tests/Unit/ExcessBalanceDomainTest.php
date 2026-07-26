<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use App\Modules\ExcessBalance\Domain\Services\ExcessAmountCalculator;
use App\Modules\ExcessBalance\Domain\Services\ExcessBalanceInvariant;
use App\Modules\ExcessBalance\Domain\Services\ExcessStateMachine;
use App\Modules\ExcessBalance\Domain\ValueObjects\Money;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use PHPUnit\Framework\TestCase;

final class ExcessBalanceDomainTest extends TestCase
{
    public function test_excess_is_exact_and_keeps_four_internal_decimals(): void
    {
        $calculator = new ExcessAmountCalculator;

        self::assertSame(
            '1600.0000',
            $calculator->calculate(new Money('10000.0000'), new Money('8400.0000'))->value(),
        );
        self::assertSame('1600.00', (new Money('1600'))->publicValue());
    }

    public function test_conservation_includes_retained_available_reserved_applied_and_refunded(): void
    {
        $invariant = new ExcessBalanceInvariant;
        $invariant->assert(
            new Money('100.0000'),
            new Money('10.0000'),
            new Money('20.0000'),
            new Money('30.0000'),
            new Money('25.0000'),
            new Money('15.0000'),
        );

        $this->expectException(ExcessBalanceException::class);
        $invariant->assert(
            new Money('100.0000'),
            Money::zero(),
            new Money('80.0000'),
            new Money('30.0000'),
            Money::zero(),
            Money::zero(),
        );
    }

    public function test_state_machine_rejects_refund_after_credit_balance_selection(): void
    {
        $this->expectException(ExcessBalanceException::class);

        (new ExcessStateMachine)->assertTransition(
            ExcessBalanceStatus::CREDIT_BALANCE,
            ExcessBalanceStatus::REFUND_PENDING,
        );
    }
}
