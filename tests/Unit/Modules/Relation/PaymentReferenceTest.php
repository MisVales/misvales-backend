<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Relation;

use App\Modules\Relation\Domain\ValueObjects\PaymentReference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaymentReferenceTest extends TestCase
{
    public function test_it_creates_payment_reference()
    {
        $reference = new PaymentReference('REF123456');
        
        $this->assertEquals('REF123456', $reference->getReference());
        $this->assertEquals('REF123456', (string) $reference);
    }

    public function test_it_throws_exception_if_empty()
    {
        $this->expectException(InvalidArgumentException::class);
        new PaymentReference('   ');
    }
}
