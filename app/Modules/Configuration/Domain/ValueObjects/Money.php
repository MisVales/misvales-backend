<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use JsonSerializable;

/**
 * Importe monetario MXN con precisión decimal exacta.
 *
 * Utiliza bcmath internamente con escala de 4 decimales.
 * El formato de salida JSON usa 2 decimales como cadena.
 * Nunca utiliza float ni double.
 */
final readonly class Money implements JsonSerializable
{
    private const int SCALE = 4;

    private string $value;

    public function __construct(string|int $value)
    {
        $normalized = is_int($value) ? (string) $value : trim($value);

        if (! preg_match('/^-?\d+(?:\.\d{1,4})?$/', $normalized)) {
            throw ConfigurationException::valueInvalid(
                'El importe no es un decimal monetario válido.'
            );
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

    public function isPositive(): bool
    {
        return $this->greaterThan(self::zero());
    }

    public function isNegative(): bool
    {
        return $this->lessThan(self::zero());
    }

    public function isNonNegative(): bool
    {
        return $this->greaterThanOrEqual(self::zero());
    }

    /**
     * Valor con escala interna de 4 decimales para persistencia.
     */
    public function databaseValue(): string
    {
        return $this->value;
    }

    /**
     * Valor con 2 decimales para intercambio JSON.
     */
    public function format(): string
    {
        return $this->isNegative()
            ? bcsub($this->value, '0.0050', 2)
            : bcadd($this->value, '0.0050', 2);
    }

    /**
     * Verifica si el importe es múltiplo exacto de un divisor.
     */
    public function isMultipleOf(int $divisor): bool
    {
        if ($divisor <= 0) {
            return false;
        }

        $remainder = bcmod($this->value, (string) $divisor, self::SCALE);

        return bccomp($remainder, '0', self::SCALE) === 0;
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
