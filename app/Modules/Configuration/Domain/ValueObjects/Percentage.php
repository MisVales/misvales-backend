<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use JsonSerializable;

/**
 * Porcentaje representado como fracción decimal exacta.
 *
 * 10% se representa como "0.1000". Utiliza bcmath con escala 4.
 * Nunca utiliza float ni double.
 */
final readonly class Percentage implements JsonSerializable
{
    private const int SCALE = 4;

    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $normalized)) {
            throw ConfigurationException::valueInvalid(
                'El porcentaje no es un decimal válido.'
            );
        }

        $this->value = bcadd($normalized, '0', self::SCALE);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    public function isNonNegative(): bool
    {
        return bccomp($this->value, '0', self::SCALE) >= 0;
    }

    public function equals(self $other): bool
    {
        return bccomp($this->value, $other->value, self::SCALE) === 0;
    }

    /**
     * Valor con escala interna de 4 decimales para persistencia.
     */
    public function databaseValue(): string
    {
        return $this->value;
    }

    /**
     * Valor con 4 decimales para intercambio JSON (fracción decimal).
     */
    public function format(): string
    {
        return $this->value;
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
