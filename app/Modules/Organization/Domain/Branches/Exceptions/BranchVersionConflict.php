<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use RuntimeException;

final class BranchVersionConflict extends RuntimeException
{
    public function __construct(string $branchId, int $expectedVersion)
    {
        parent::__construct("La sucursal {$branchId} ya no se encuentra en la versión {$expectedVersion}.");
    }
}
