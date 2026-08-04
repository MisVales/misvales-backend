<?php

namespace App\Modules\Organization\Domain\Branches\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class BranchCode implements Stringable
{
    public const MAX_LENGTH = 20;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = mb_strtoupper(trim($value));

        if ($value === '') {
            throw new InvalidArgumentException('El código de la sucursal es obligatorio.');
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('El código de la sucursal no puede exceder 20 caracteres.');
        }

        if (preg_match('/\A[A-Z0-9][A-Z0-9_-]*\z/', $value) !== 1) {
            throw new InvalidArgumentException('El código de la sucursal solo admite letras, números, guiones y guiones bajos.');
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
