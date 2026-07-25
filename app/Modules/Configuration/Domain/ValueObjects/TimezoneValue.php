<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use DateTimeZone;
use JsonSerializable;

/**
 * Zona horaria validada contra DateTimeZone de PHP.
 *
 * La zona operativa del sistema es "America/Monterrey" (regla 60).
 */
final readonly class TimezoneValue implements JsonSerializable
{
    private string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        try {
            new DateTimeZone($normalized);
        } catch (\Exception) {
            throw ConfigurationException::valueInvalid(
                "La zona horaria «{$normalized}» no es válida."
            );
        }

        $this->value = $normalized;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function toDateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->value);
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
