<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\PaymentBehaviorRule;
use PHPUnit\Framework\TestCase;

final class PaymentBehaviorRuleTest extends TestCase
{
    public function test_can_create_valid_rule(): void
    {
        $data = [
            "behavior" => "ON_TIME_PAYMENT",
            "generates_points" => true,
            "reduces_points" => false
        ];
        
        $rule = PaymentBehaviorRule::fromArray($data);
        
        $this->assertEquals('ON_TIME_PAYMENT', $rule->behavior->value);
        $this->assertTrue($rule->generatesPoints);
        $this->assertFalse($rule->reducesPoints);
    }

    public function test_rejects_missing_keys(): void
    {
        $this->expectException(\Error::class);
        PaymentBehaviorRule::fromArray(['behavior' => 'ON_TIME_PAYMENT']);
    }

    public function test_rejects_invalid_behavior(): void
    {
        $this->expectException(\ValueError::class);
        PaymentBehaviorRule::fromArray([
            "behavior" => "INVALID_BEHAVIOR",
            "generates_points" => true,
            "reduces_points" => false
        ]);
    }
}
