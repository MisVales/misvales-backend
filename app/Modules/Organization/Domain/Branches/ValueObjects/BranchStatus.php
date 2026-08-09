<?php

namespace App\Modules\Organization\Domain\Branches\ValueObjects;

use InvalidArgumentException;

enum BranchStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';

    public static function fromString(string $value): self
    {
        return self::tryFrom(mb_strtoupper(trim($value)))
            ?? throw new InvalidArgumentException('El estado de la sucursal debe ser ACTIVE o INACTIVE.');
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
