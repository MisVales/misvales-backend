<?php

namespace App\Modules\Organization\Domain\Assignments\Exceptions;

use DomainException;

final class RoleScopeNotAllowed extends DomainException
{
    public function __construct(string $roleCode, string $scope)
    {
        parent::__construct("El rol {$roleCode} no admite el alcance {$scope}.");
    }
}
