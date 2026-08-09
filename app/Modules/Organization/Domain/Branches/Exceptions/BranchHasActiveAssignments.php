<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use DomainException;

final class BranchHasActiveAssignments extends DomainException
{
    public function __construct(string $branchId)
    {
        parent::__construct("La sucursal {$branchId} conserva asignaciones activas.");
    }
}
