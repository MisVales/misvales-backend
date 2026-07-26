<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_can_create_from_valid_amount(): void
    {
        $money = new Money('100.50');
        $this->assertEquals('100.5000', $money->databaseValue());
        $this->assertTrue($money->isPositive());
    }

    public function test_rejects_invalid_numeric_format(): void
    {
        $this->expectException(ConfigurationException::class);
        new Money('100,50');
    }

    public function test_rejects_non_numeric_string(): void
    {
        $this->expectException(ConfigurationException::class);
        new Money('abc');
    }

    public function test_handles_zero_correctly(): void
    {
        $money = new Money('0');
        $this->assertEquals('0.0000', $money->databaseValue());
        $this->assertFalse($money->isPositive());
        $this->assertTrue($money->isNonNegative());
    }

    public function test_handles_negative_amounts(): void
    {
        $money = new Money('-50.25');
        $this->assertEquals('-50.2500', $money->databaseValue());
        $this->assertFalse($money->isPositive());
        $this->assertFalse($money->isNonNegative());
    }

    public function test_is_multiple_of(): void
    {
        $money = new Money('500.00');
        $this->assertTrue($money->isMultipleOf(100));
        $this->assertFalse($money->isMultipleOf(300));

        $money2 = new Money('150.50');
        $this->assertFalse($money2->isMultipleOf(50));
    }
}
