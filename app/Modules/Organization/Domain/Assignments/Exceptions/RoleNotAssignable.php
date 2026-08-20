<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class RoleNotAssignable extends DomainException
{
    public function __construct(string $roleCode)
    {
        if ($roleCode === 'distributor') {
            parent::__construct('El rol de distribuidora se obtiene únicamente mediante el proceso de autorización de distribuidoras.');
        } else {
            parent::__construct("El rol de {$roleCode} no puede asignarse manualmente.");
        }
    }
}
