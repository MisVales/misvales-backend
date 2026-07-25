<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\TimezoneValue;
use PHPUnit\Framework\TestCase;

final class TimezoneValueTest extends TestCase
{
    public function test_can_create_valid_timezone(): void
    {
        $tz = new TimezoneValue('America/Monterrey');
        $this->assertEquals('America/Monterrey', $tz->value());
        
        $tz2 = new TimezoneValue('UTC');
        $this->assertEquals('UTC', $tz2->value());
    }

    public function test_rejects_invalid_timezone(): void
    {
        $this->expectException(ConfigurationException::class);
        new TimezoneValue('Invalid/Timezone');
    }
}
