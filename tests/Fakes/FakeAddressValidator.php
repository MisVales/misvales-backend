<?php

namespace Tests\Fakes;

use App\Modules\Organization\Application\Branches\AddressValidator;
use App\Modules\Organization\Domain\Branches\ValidatedAddress;

final class FakeAddressValidator implements AddressValidator
{
    public function validate(string $address): ValidatedAddress
    {
        return new ValidatedAddress(
            formatted: trim($address),
            validationId: 'test-address-validation-id',
            placeId: 'test-place-id',
            latitude: 25.5428,
            longitude: -103.4068,
        );
    }
}
