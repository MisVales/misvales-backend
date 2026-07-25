<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Registration;

/** Entrada estructurada de domicilio; no admite una cadena libre sustitutiva. */
final readonly class AddressInput
{
    public function __construct(
        public string $street,
        public string $exteriorNumber,
        public ?string $interiorNumber,
        public string $neighborhood,
        public string $postalCode,
        public string $municipality,
        public string $city,
        public string $state,
    ) {}

    /** @return array{street:string,exterior_number:string,interior_number:?string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string} */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'exterior_number' => $this->exteriorNumber,
            'interior_number' => $this->interiorNumber,
            'neighborhood' => $this->neighborhood,
            'postal_code' => $this->postalCode,
            'municipality' => $this->municipality,
            'city' => $this->city,
            'state' => $this->state,
        ];
    }
}
