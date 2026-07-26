<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Domain\ValueObjects;

use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;

/** Decimal MXN exacto con cuatro decimales internos y dos en el contrato público. */
final readonly class Money
{
    private string $amount;

    public function __construct(string|int $amount)
    {
        $candidate = trim((string) $amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $candidate)) {
            throw ExcessBalanceException::amountInvalid();
        }

        $this->amount = bcadd($candidate, '0', 4);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->amount, $other->amount, 4));
    }

    public function subtract(self $other): self
    {
        return new self(bcsub($this->amount, $other->amount, 4));
    }

    public function min(self $other): self
    {
        return $this->lessThanOrEqual($other) ? $this : $other;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', 4) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', 4) === -1;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) <= 0;
    }

    public function value(): string
    {
        return $this->amount;
    }

    public function publicValue(): string
    {
        return bcadd($this->amount, '0', 2);
    }
}
