<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use RuntimeException;

final class OrganizationScopeDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('La operación solicitada está fuera del alcance organizacional autorizado.');
    }
}
