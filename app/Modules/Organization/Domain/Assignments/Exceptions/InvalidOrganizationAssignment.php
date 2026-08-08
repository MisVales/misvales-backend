<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class InvalidOrganizationAssignment extends DomainException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
