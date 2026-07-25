<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Credit\Domain\Aggregates\CreditLine;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Domain\Rules\FiftyPercentRule;
use App\Modules\Credit\Domain\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class CreditDomainTest extends TestCase
{
    public function test_money_keeps_four_decimals_and_rounds_final_output_arithmetically(): void
    {
        self::assertSame('100.0050', (new Money('100.005'))->databaseValue());
        self::assertSame('100.01', (new Money('100.005'))->format());
        self::assertSame('-100.01', (new Money('-100.005'))->format());
        self::assertSame('0.30', (new Money('0.1'))->add(new Money('0.2'))->format());
    }

    public function test_line_invariants_usage_increase_and_limited_recovery(): void
    {
        $line = new CreditLine(new Money('20000'), Money::zero(), Money::zero());
        $used = $line->useCapital(new Money('10000'));
        self::assertSame('10000.00', $used->availableBalance()->format());

        $increased = $used->increase(new Money('5000'));
        self::assertSame('25000.00', $increased->totalAuthorized->format());
        [$recovered, $applied] = $increased->recoverCapital(new Money('12000'));
        self::assertSame('10000.00', $applied->format());
        self::assertSame('0.00', $recovered->usedBalance->format());
        self::assertSame('10000.00', $recovered->recoveredCapitalTotal->format());
    }

    public function test_line_rejects_usage_above_available_balance(): void
    {
        $this->expectException(CreditRuleViolation::class);
        $this->expectExceptionMessage('saldo disponible');
        (new CreditLine(new Money('100'), Money::zero(), Money::zero()))
            ->useCapital(new Money('100.01'));
    }

    public function test_fifty_percent_range_is_inclusive_and_capped_by_available_balance(): void
    {
        $rule = new FiftyPercentRule;
        $range = $rule->range(new Money('20000'), new Money('20000'), new Money('500'));
        self::assertSame('10000.00', $range->reference->format());
        self::assertSame('9500.00', $range->lower->format());
        self::assertSame('10500.00', $range->upper->format());
        self::assertTrue($range->admits(new Money('9500')));
        self::assertTrue($range->admits(new Money('10500')));
        self::assertFalse($range->admits(new Money('9400')));
        self::assertFalse($range->admits(new Money('10600')));

        $capped = $rule->range(new Money('20000'), new Money('9700'), new Money('500'));
        self::assertSame('9700.00', $capped->upper->format());
    }

    public function test_fifty_percent_rule_detects_when_no_amount_is_admissible(): void
    {
        $rule = new FiftyPercentRule;
        $range = $rule->range(new Money('20000'), new Money('9000'), new Money('500'));
        self::assertFalse($range->hasAdmissibleAmount());

        $this->expectException(CreditRuleViolation::class);
        $rule->assertAdmissible($range, new Money('9000'));
    }

    public function test_custom_tolerance_is_used_without_hardcoding_five_hundred(): void
    {
        $range = (new FiftyPercentRule)->range(new Money('20000'), new Money('20000'), new Money('750'));
        self::assertSame('9250.00', $range->lower->format());
        self::assertSame('10750.00', $range->upper->format());
    }
}
