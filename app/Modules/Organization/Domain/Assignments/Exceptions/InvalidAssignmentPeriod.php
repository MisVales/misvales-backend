<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class InvalidAssignmentPeriod extends DomainException
{
    public function __construct()
    {
        parent::__construct('La fecha de finalización debe ser posterior al inicio de la asignación.');
    }
}
