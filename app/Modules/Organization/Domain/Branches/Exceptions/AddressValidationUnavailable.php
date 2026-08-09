<?php

namespace App\Modules\Organization\Domain\Branches\Exceptions;

use RuntimeException;

final class AddressValidationUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El servicio de validación de direcciones no está disponible. Intente nuevamente más tarde.');
    }
}
