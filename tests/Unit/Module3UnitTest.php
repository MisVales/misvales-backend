<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Models\ConfigurationVersion;

class Module3UnitTest extends TestCase
{
    public function test_decimal_format_prevents_float_precision_loss()
    {
        $version = new ConfigurationVersion();
        $version->value = '1000.50';
        $this->assertIsString($version->value);
        $this->assertEquals('1000.50', $version->value);
    }
}
