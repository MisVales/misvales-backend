<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\ValueObjects;

use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    private const SCALE = 4;

    private string $value;

    public function __construct(string|int $value)
    {
        $normalized = is_int($value) ? (string) $value : trim($value);
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $normalized)) {
            throw new CreditRuleViolation('El importe no es un decimal monetario válido.', 'CREDIT_INCREASE_AMOUNT_INVALID', 422);
        }

        $this->value = bcadd($normalized, '0', self::SCALE);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    public function subtract(self $other): self
    {
        return new self(bcsub($this->value, $other->value, self::SCALE));
    }

    public function multiply(string $factor): self
    {
        return new self(bcmul($this->value, $factor, self::SCALE));
    }

    public function min(self $other): self
    {
        return $this->lessThan($other) ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->greaterThan($other) ? $this : $other;
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->compare($other) <= 0;
    }

    public function isPositive(): bool
    {
        return $this->greaterThan(self::zero());
    }

    public function isNegative(): bool
    {
        return $this->lessThan(self::zero());
    }

    public function databaseValue(): string
    {
        return $this->value;
    }

    public function format(): string
    {
        return $this->isNegative()
            ? bcsub($this->value, '0.0050', 2)
            : bcadd($this->value, '0.0050', 2);
    }

    public function jsonSerialize(): string
    {
        return $this->format();
    }

    public function __toString(): string
    {
        return $this->databaseValue();
    }
}
