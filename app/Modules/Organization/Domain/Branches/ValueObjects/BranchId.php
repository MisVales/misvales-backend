<?php

namespace App\Modules\Organization\Domain\Branches\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class BranchId implements Stringable
{
    private const UUID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('El identificador de la sucursal debe ser un UUID válido.');
        }

        return new self(strtolower($value));
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
