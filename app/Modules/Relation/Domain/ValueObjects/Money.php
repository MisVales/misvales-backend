<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    private string $amount;

    public function __construct(string $amount)
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Amount must be numeric');
        }

        // Ensure 4 decimal precision internally
        $this->amount = bcadd($amount, '0', 4);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public static function fromString(string $amount): self
    {
        return new self($amount);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getRoundedAmount(): string
    {
        // Round arithmetic to 2 decimals
        return number_format((float) $this->amount, 2, '.', '');
    }

    public function add(Money $other): self
    {
        return new self(bcadd($this->amount, $other->getAmount(), 4));
    }

    public function subtract(Money $other): self
    {
        return new self(bcsub($this->amount, $other->getAmount(), 4));
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', 4) === -1;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', 4) === 0;
    }

    public function isGreaterThan(Money $other): bool
    {
        return bccomp($this->amount, $other->getAmount(), 4) === 1;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        return bccomp($this->amount, $other->getAmount(), 4) >= 0;
    }

    public function equals(Money $other): bool
    {
        return bccomp($this->amount, $other->getAmount(), 4) === 0;
    }

    public function jsonSerialize(): string
    {
        return $this->getRoundedAmount();
    }
}
