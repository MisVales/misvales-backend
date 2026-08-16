<?php

namespace App\Modules\Organization\Domain\Branches;

use InvalidArgumentException;

final readonly class ValidatedAddress
{
    public function __construct(
        public string $formatted,
        public ?string $validationId,
        public ?string $placeId,
        public ?float $latitude,
        public ?float $longitude,
    ) {
        if (trim($formatted) === '' || mb_strlen($formatted) > 500) {
            throw new InvalidArgumentException('La dirección validada no es válida.');
        }

        if ($validationId !== null && trim($validationId) === '') {
            throw new InvalidArgumentException('La dirección no contiene evidencia de validación.');
        }
    }
}
