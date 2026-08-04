<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use RuntimeException;

final class DuplicateBranchCode extends RuntimeException
{
    public function __construct(string $code)
    {
        parent::__construct("El código de sucursal {$code} ya está registrado.");
    }
}
