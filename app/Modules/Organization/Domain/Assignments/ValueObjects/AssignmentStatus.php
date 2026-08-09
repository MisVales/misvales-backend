<?php

namespace App\Modules\Organization\Domain\Assignments\ValueObjects;

use InvalidArgumentException;

enum AssignmentStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ENDED = 'ENDED';
    case REVOKED = 'REVOKED';

    public static function fromString(string $value): self
    {
        return self::tryFrom(mb_strtoupper(trim($value)))
            ?? throw new InvalidArgumentException('El estado de la asignación no es válido.');
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
