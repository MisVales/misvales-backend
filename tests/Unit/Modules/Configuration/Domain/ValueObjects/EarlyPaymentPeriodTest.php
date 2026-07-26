<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\EarlyPaymentPeriod;
use PHPUnit\Framework\TestCase;

final class EarlyPaymentPeriodTest extends TestCase
{
    public function test_can_create_valid_period(): void
    {
        $json = '{"start_offset_days": -5, "start_time": "00:00:00", "end_offset_days": 0, "end_time": "23:59:59", "timezone": "America/Monterrey"}';
        $period = EarlyPaymentPeriod::fromJson($json);

        $this->assertEquals(-5, $period->startOffsetDays);
        $this->assertEquals(0, $period->endOffsetDays);
        $this->assertEquals('00:00:00', $period->startTime);
    }

    public function test_rejects_invalid_json(): void
    {
        $this->expectException(ConfigurationException::class);
        EarlyPaymentPeriod::fromJson('{malformed_json}');
    }

    public function test_rejects_missing_keys(): void
    {
        $this->expectException(ConfigurationException::class);
        EarlyPaymentPeriod::fromJson('{"start_offset_days": -5, "end_offset_days": 0}');
    }

    public function test_rejects_invalid_day_range(): void
    {
        $this->expectException(ConfigurationException::class);
        // start_offset_days > end_offset_days
        EarlyPaymentPeriod::fromJson('{"start_offset_days": 5, "start_time": "00:00:00", "end_offset_days": 0, "end_time": "23:59:59", "timezone": "America/Monterrey"}');
    }
}
