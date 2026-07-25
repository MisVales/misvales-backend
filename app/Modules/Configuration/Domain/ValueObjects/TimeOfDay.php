<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use JsonSerializable;

/**
 * Hora del día con segundos (H:i:s).
 *
 * Representa un momento diario exacto como "00:05:00" o "23:59:59".
 */
final readonly class TimeOfDay implements JsonSerializable
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $normalized)) {
            throw ConfigurationException::valueInvalid(
                'La hora no tiene un formato válido (HH:MM:SS).'
            );
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
