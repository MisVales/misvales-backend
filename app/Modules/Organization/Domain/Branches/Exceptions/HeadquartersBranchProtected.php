<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use DomainException;

final class HeadquartersBranchProtected extends DomainException
{
    public function __construct()
    {
        parent::__construct('La sucursal matriz no puede desactivarse.');
    }
}
