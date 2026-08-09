<?php

namespace App\Modules\Organization\Infrastructure\GoogleMaps;

use App\Modules\Organization\Application\Branches\AddressValidator;
use App\Modules\Organization\Domain\Branches\Exceptions\AddressValidationUnavailable;
use App\Modules\Organization\Domain\Branches\Exceptions\InvalidBranchAddress;
use App\Modules\Organization\Domain\Branches\ValidatedAddress;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GoogleMapsAddressValidator implements AddressValidator
{
    public function validate(string $address): ValidatedAddress
    {
        $apiKey = config('services.google_maps.address_validation_key');
        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new AddressValidationUnavailable;
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(10)
                ->retry(2, 200, throw: false)
                ->withQueryParameters(['key' => $apiKey])
                ->post('https://addressvalidation.googleapis.com/v1:validateAddress', [
                    'address' => [
                        'regionCode' => 'MX',
                        'languageCode' => 'es',
                        'addressLines' => [trim($address)],
                    ],
                ]);
        } catch (Throwable) {
            throw new AddressValidationUnavailable;
        }

        if (! $response->successful()) {
            throw new AddressValidationUnavailable;
        }

        $verdict = $response->json('result.verdict', []);
        $granularity = $verdict['validationGranularity'] ?? null;
        $nextAction = $verdict['possibleNextAction'] ?? null;
        $acceptable = ($verdict['addressComplete'] ?? false) === true
            && in_array($granularity, ['PREMISE', 'SUB_PREMISE'], true)
            && $nextAction !== 'FIX';

        if (! $acceptable) {
            throw new InvalidBranchAddress;
        }

        $formatted = $response->json('result.address.formattedAddress');
        $validationId = $response->json('responseId');
        if (! is_string($formatted) || ! is_string($validationId)) {
            throw new AddressValidationUnavailable;
        }

        return new ValidatedAddress(
            formatted: $formatted,
            validationId: $validationId,
            placeId: $response->json('result.geocode.placeId'),
            latitude: $this->number($response->json('result.geocode.location.latitude')),
            longitude: $this->number($response->json('result.geocode.location.longitude')),
        );
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
