<?php

namespace App\Modules\Organization\Domain\Branches\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class BranchName implements Stringable
{
    public const MAX_LENGTH = 150;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('El nombre de la sucursal es obligatorio.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('El nombre de la sucursal no puede exceder 150 caracteres.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
