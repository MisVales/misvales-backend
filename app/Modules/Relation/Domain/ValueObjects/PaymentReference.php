<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\ValueObjects;

use InvalidArgumentException;

final readonly class PaymentReference
{
    private string $reference;

    public function __construct(string $reference)
    {
        if (empty(trim($reference))) {
            throw new InvalidArgumentException('Payment reference cannot be empty');
        }

        $this->reference = trim($reference);
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function equals(PaymentReference $other): bool
    {
        return $this->reference === $other->getReference();
    }

    public function __toString(): string
    {
        return $this->reference;
    }
}
