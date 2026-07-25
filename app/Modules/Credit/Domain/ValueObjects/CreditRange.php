<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\ValueObjects;

final readonly class CreditRange
{
    public function __construct(
        public Money $reference,
        public Money $tolerance,
        public Money $lower,
        public Money $upper,
    ) {}

    public function admits(Money $capital): bool
    {
        return $this->hasAdmissibleAmount()
            && $capital->greaterThanOrEqual($this->lower)
            && $capital->lessThanOrEqual($this->upper);
    }

    public function hasAdmissibleAmount(): bool
    {
        return $this->upper->greaterThanOrEqual($this->lower);
    }
}
