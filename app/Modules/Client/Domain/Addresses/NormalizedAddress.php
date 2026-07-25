<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Addresses;

/** Conserva valores de presentación y la forma canónica usada solo para HMAC. */
final readonly class NormalizedAddress
{
    /**
     * @param  array{street:string,exterior_number:string,interior_number:?string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string}  $display
     * @param  array{street:string,exterior_number:string,interior_number:string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string}  $canonical
     */
    public function __construct(
        public array $display,
        public array $canonical,
        public string $version,
    ) {}

    public function fingerprintInput(): string
    {
        return $this->version.'|'.json_encode($this->canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
