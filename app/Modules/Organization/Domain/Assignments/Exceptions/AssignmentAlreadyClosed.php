<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class AssignmentAlreadyClosed extends DomainException
{
    public function __construct(string $assignmentId)
    {
        parent::__construct("La asignación {$assignmentId} ya se encuentra finalizada.");
    }
}
