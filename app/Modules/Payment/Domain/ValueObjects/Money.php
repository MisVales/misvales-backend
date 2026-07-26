<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain\ValueObjects;

use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;

/**
 * Decimal monetario exacto con cuatro decimales internos.
 *
 * No convierte a float y redondea aritméticamente únicamente al construirlo.
 */
final readonly class Money
{
    private string $amount;

    public function __construct(string|int $amount)
    {
        $candidate = is_int($amount) ? (string) $amount : trim($amount);
        if (! preg_match('/^-?\d+(?:\.\d{1,8})?$/', $candidate)) {
            throw PaymentDomainException::invalidMoney();
        }

        $normalized = bcadd($candidate, '0', 4);
        if (bccomp($candidate, $normalized, 8) !== 0) {
            $normalized = $this->roundToFour($candidate);
        }
        $this->amount = $normalized === '-0.0000' ? '0.0000' : $normalized;
    }

    public static function zero(): self
    {
        return new self('0');
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

    public function max(self $other): self
    {
        return $this->greaterThanOrEqual($other) ? $this : $other;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', 4) === 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', 4) === 1;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', 4) === -1;
    }

    public function greaterThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) === 1;
    }

    public function greaterThanOrEqual(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) >= 0;
    }

    public function lessThan(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) === -1;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) <= 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->amount, $other->amount, 4) === 0;
    }

    public function value(): string
    {
        return $this->amount;
    }

    public function finalAmount(): string
    {
        return $this->roundToScale($this->amount, 2);
    }

    private function roundToFour(string $amount): string
    {
        return $this->roundToScale($amount, 4);
    }

    private function roundToScale(string $amount, int $scale): string
    {
        $negative = str_starts_with($amount, '-');
        $absolute = ltrim($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, $scale + 1, '0');
        $base = $whole.'.'.substr($fraction, 0, $scale);
        if ((int) $fraction[$scale] >= 5) {
            $increment = '0.'.str_repeat('0', max(0, $scale - 1)).'1';
            $base = bcadd($base, $increment, $scale);
        }

        return $negative ? bcmul($base, '-1', $scale) : $base;
    }
}
