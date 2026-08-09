<?php

namespace Tests\Unit\Modules\Organization\Infrastructure\GoogleMaps;

use App\Modules\Organization\Domain\Branches\Exceptions\AddressValidationUnavailable;
use App\Modules\Organization\Domain\Branches\Exceptions\InvalidBranchAddress;
use App\Modules\Organization\Infrastructure\GoogleMaps\GoogleMapsAddressValidator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GoogleMapsAddressValidatorTest extends TestCase
{
    public function test_it_returns_the_google_formatted_premise_address(): void
    {
        config()->set('services.google_maps.address_validation_key', 'test-key');
        Http::fake([
            'addressvalidation.googleapis.com/*' => Http::response([
                'responseId' => 'response-id',
                'result' => [
                    'verdict' => [
                        'addressComplete' => true,
                        'validationGranularity' => 'PREMISE',
                        'possibleNextAction' => 'ACCEPT',
                    ],
                    'address' => ['formattedAddress' => 'Blvd. Independencia 100, Torreón, Coahuila 27000, México'],
                    'geocode' => [
                        'placeId' => 'place-id',
                        'location' => ['latitude' => 25.5428, 'longitude' => -103.4068],
                    ],
                ],
            ]),
        ]);

        $result = (new GoogleMapsAddressValidator)->validate('Blvd. Independencia 100, Torreón');

        self::assertSame('Blvd. Independencia 100, Torreón, Coahuila 27000, México', $result->formatted);
        self::assertSame('response-id', $result->validationId);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://addressvalidation.googleapis.com/v1:validateAddress?key=test-key'
            && $request['address']['regionCode'] === 'MX'
            && $request['address']['addressLines'] === ['Blvd. Independencia 100, Torreón']);
    }

    public function test_it_rejects_an_incomplete_address(): void
    {
        config()->set('services.google_maps.address_validation_key', 'test-key');
        Http::fake([
            '*' => Http::response([
                'responseId' => 'response-id',
                'result' => [
                    'verdict' => [
                        'addressComplete' => false,
                        'validationGranularity' => 'ROUTE',
                        'possibleNextAction' => 'FIX',
                    ],
                ],
            ]),
        ]);

        $this->expectException(InvalidBranchAddress::class);

        (new GoogleMapsAddressValidator)->validate('Torreón');
    }

    public function test_it_fails_closed_without_an_api_key(): void
    {
        config()->set('services.google_maps.address_validation_key');

        $this->expectException(AddressValidationUnavailable::class);

        (new GoogleMapsAddressValidator)->validate('Blvd. Independencia 100, Torreón');
    }
}
