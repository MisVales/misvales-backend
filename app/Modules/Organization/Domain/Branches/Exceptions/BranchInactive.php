<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use DomainException;

final class BranchInactive extends DomainException
{
    public function __construct(string $branchId)
    {
        parent::__construct("La sucursal {$branchId} se encuentra inactiva.");
    }
}
