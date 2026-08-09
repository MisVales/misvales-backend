<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use DomainException;

final class InvalidBranchAddress extends DomainException
{
    public function __construct()
    {
        parent::__construct('Google Maps no pudo confirmar una dirección completa a nivel de inmueble. Revise calle, número, colonia, ciudad, estado y código postal.');
    }
}
