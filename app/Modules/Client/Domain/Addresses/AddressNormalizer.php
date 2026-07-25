<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Addresses;

use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use Illuminate\Support\Str;

/** Normalizador versionado; v1 no introduce equivalencias de abreviaturas no aprobadas. */
final class AddressNormalizer
{
    public const VERSION = '1';

    private const MAX_LENGTHS = [
        'street' => 200,
        'exterior_number' => 40,
        'interior_number' => 40,
        'neighborhood' => 160,
        'postal_code' => 20,
        'municipality' => 160,
        'city' => 160,
        'state' => 120,
    ];

    /**
     * @param  array{street:string,exterior_number:string,interior_number?:?string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string}  $address
     */
    public function normalize(array $address): NormalizedAddress
    {
        $display = [
            'street' => $this->display('street', $address['street']),
            'exterior_number' => $this->display('exterior_number', $address['exterior_number']),
            'interior_number' => $this->nullableDisplay('interior_number', $address['interior_number'] ?? null),
            'neighborhood' => $this->display('neighborhood', $address['neighborhood']),
            'postal_code' => $this->display('postal_code', $address['postal_code']),
            'municipality' => $this->display('municipality', $address['municipality']),
            'city' => $this->display('city', $address['city']),
            'state' => $this->display('state', $address['state']),
        ];

        $canonical = [];
        foreach ($display as $key => $value) {
            $canonical[$key] = $value === null ? '' : $this->canonical($value);
        }

        /** @var array{street:string,exterior_number:string,interior_number:string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string} $canonical */
        return new NormalizedAddress($display, $canonical, self::VERSION);
    }

    private function display(string $field, string $value): string
    {
        $withoutMarkup = strip_tags($value);
        $normalized = preg_replace('/\s+/u', ' ', trim($withoutMarkup)) ?? trim($withoutMarkup);
        if ($normalized === '' || mb_strlen($normalized) > self::MAX_LENGTHS[$field]) {
            throw ClientDomainException::addressInvalid();
        }

        return $normalized;
    }

    private function nullableDisplay(string $field, ?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $display = preg_replace('/\s+/u', ' ', trim(strip_tags($value))) ?? trim(strip_tags($value));

        if ($display === '') {
            return null;
        }
        if (mb_strlen($display) > self::MAX_LENGTHS[$field]) {
            throw ClientDomainException::addressInvalid();
        }

        return $display;
    }

    private function canonical(string $value): string
    {
        $withoutAccents = Str::ascii($value);
        $upper = mb_strtoupper($withoutAccents, 'UTF-8');
        $withoutPunctuation = preg_replace('/[.,#-]+/u', ' ', $upper) ?? $upper;

        return preg_replace('/\s+/u', ' ', trim($withoutPunctuation)) ?? trim($withoutPunctuation);
    }
}
