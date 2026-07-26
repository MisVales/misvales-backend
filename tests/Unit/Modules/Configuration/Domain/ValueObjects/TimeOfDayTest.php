<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\TimeOfDay;
use PHPUnit\Framework\TestCase;

final class TimeOfDayTest extends TestCase
{
    public function test_can_create_valid_time(): void
    {
        $time = new TimeOfDay('14:30:00');
        $this->assertEquals('14:30:00', $time->value());

        $time2 = new TimeOfDay('09:05:15');
        $this->assertEquals('09:05:15', $time2->value());
    }

    public function test_rejects_invalid_time_format(): void
    {
        $this->expectException(ConfigurationException::class);
        new TimeOfDay('25:00');
    }

    public function test_rejects_malformed_string(): void
    {
        $this->expectException(ConfigurationException::class);
        new TimeOfDay('14-30');
    }
}
