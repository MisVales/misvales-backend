<?php

namespace App\Modules\Organization\Domain\Assignments\ValueObjects;

use InvalidArgumentException;

enum OrganizationScope: string
{
    case GLOBAL = 'GLOBAL';
    case BRANCH = 'BRANCH';
    case ASSIGNED = 'ASSIGNED';
    case SELF = 'SELF';

    public static function fromString(string $value): self
    {
        return self::tryFrom(mb_strtoupper(trim($value)))
            ?? throw new InvalidArgumentException('El tipo de alcance organizacional no es válido.');
    }

    public function requiresBranch(): bool
    {
        return $this === self::BRANCH;
    }
}
