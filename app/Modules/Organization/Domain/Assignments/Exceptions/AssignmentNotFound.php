<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use RuntimeException;

final class AssignmentNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se encontró la asignación solicitada.');
    }
}
