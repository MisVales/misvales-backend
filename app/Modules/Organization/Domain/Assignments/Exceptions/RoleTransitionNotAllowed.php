<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class RoleTransitionNotAllowed extends DomainException
{
    public function __construct(string $fromRoleCode, string $toRoleCode)
    {
        parent::__construct("La transición de {$fromRoleCode} a {$toRoleCode} no está permitida.");
    }
}
