<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Relation;

use App\Modules\Relation\Domain\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_creates_money_with_four_decimals()
    {
        $money = Money::fromString('100.5');

        $this->assertEquals('100.5000', $money->getAmount());
    }

    public function test_it_adds_money_correctly()
    {
        $m1 = Money::fromString('10.1234');
        $m2 = Money::fromString('20.1234');

        $result = $m1->add($m2);

        $this->assertEquals('30.2468', $result->getAmount());
    }

    public function test_it_rounds_to_two_decimals_for_presentation()
    {
        $money = Money::fromString('10.1255');

        $this->assertEquals('10.13', $money->getRoundedAmount());
    }

    public function test_it_throws_exception_if_not_numeric()
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromString('not_a_number');
    }
}
