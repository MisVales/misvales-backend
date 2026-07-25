<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\Percentage;
use PHPUnit\Framework\TestCase;

final class PercentageTest extends TestCase
{
    public function test_can_create_from_valid_percentage(): void
    {
        $percentage = new Percentage('5.25');
        $this->assertEquals('5.2500', $percentage->databaseValue());
        $this->assertTrue($percentage->isNonNegative());
    }

    public function test_rejects_invalid_numeric_format(): void
    {
        $this->expectException(ConfigurationException::class);
        new Percentage('5,25');
    }

    public function test_handles_zero_correctly(): void
    {
        $percentage = new Percentage('0');
        $this->assertEquals('0.0000', $percentage->databaseValue());
        $this->assertTrue($percentage->isNonNegative());
    }

    public function test_rejects_negative_percentages(): void
    {
        $this->expectException(ConfigurationException::class);
        new Percentage('-10.5');
    }
}
