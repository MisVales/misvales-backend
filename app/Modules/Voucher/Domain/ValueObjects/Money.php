<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\ValueObjects;

use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use JsonSerializable;

final readonly class Money implements JsonSerializable
{
    public const SCALE = 4;

    private string $value;

    public function __construct(string|int $value)
    {
        $value = trim((string) $value);
        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $value)) {
            throw VoucherDomainException::rule('VOUCHER_MONEY_INVALID', 'El importe monetario no es válido.', 422);
        }

        $this->value = bcadd($value, '0', self::SCALE);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function add(self $other): self
    {
        return new self(bcadd($this->value, $other->value, self::SCALE));
    }

    public function subtract(self $other): self
    {
        return new self(bcsub($this->value, $other->value, self::SCALE));
    }

    public function multiply(Percentage|string|int $factor): self
    {
        $factor = $factor instanceof Percentage ? $factor->value() : (string) $factor;

        return new self(self::round(bcmul($this->value, $factor, 8), self::SCALE));
    }

    public function divide(int $divisor): self
    {
        if ($divisor < 1) {
            throw VoucherDomainException::rule('VOUCHER_CALCULATION_INVALID', 'El divisor debe ser positivo.', 422);
        }

        return new self(self::round(bcdiv($this->value, (string) $divisor, 8), self::SCALE));
    }

    public function rounded(int $scale = 2): self
    {
        return new self(self::round($this->value, $scale));
    }

    public function compare(self $other): int
    {
        return bccomp($this->value, $other->value, self::SCALE);
    }

    public function isNegative(): bool
    {
        return $this->compare(self::zero()) < 0;
    }

    public function databaseValue(): string
    {
        return $this->value;
    }

    public function format(): string
    {
        return bcadd(self::round($this->value, 2), '0', 2);
    }

    public function jsonSerialize(): string
    {
        return $this->format();
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function round(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($value, '-')
            ? bcsub($value, $increment, $scale + 1)
            : bcadd($value, $increment, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }
}
