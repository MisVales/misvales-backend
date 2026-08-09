<?php

namespace App\Modules\Organization\Application\Branches;

use App\Modules\Organization\Domain\Branches\ValidatedAddress;

interface AddressValidator
{
    public function validate(string $address): ValidatedAddress;
}
