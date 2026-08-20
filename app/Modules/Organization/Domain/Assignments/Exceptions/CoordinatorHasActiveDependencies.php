<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class CoordinatorHasActiveDependencies extends DomainException
{
    public readonly int $count;

    public function __construct(string $userId, int $count)
    {
        $this->count = $count;
        parent::__construct("Este coordinador tiene {$count} distribuidoras activas asignadas. Reasigna sus distribuidoras antes de cambiar su puesto.");
    }
}
