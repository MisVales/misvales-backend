<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class DuplicateActiveAssignment extends DomainException
{
    public function __construct()
    {
        parent::__construct('El usuario ya cuenta con esta asignación activa.');
    }
}
