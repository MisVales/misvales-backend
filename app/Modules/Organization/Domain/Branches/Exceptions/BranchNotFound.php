<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use RuntimeException;

final class BranchNotFound extends RuntimeException
{
    public function __construct(string $branchId)
    {
        parent::__construct("No se encontró la sucursal {$branchId}.");
    }
}
