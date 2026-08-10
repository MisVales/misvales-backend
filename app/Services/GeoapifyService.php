<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GeoapifyService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.geoapify.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.geoapify.key');
    }

    /**
     * Get autocomplete suggestions for a street address in a specific area.
     */
    public function autocomplete(string $text, string $city, string $state, string $postcode)
    {
        // Add Mexico filter. We can refine the text query.
        $query = [
            'text' => $text . ' ' . $postcode . ' ' . $city . ' ' . $state,
            'filter' => 'countrycode:mx',
            'apiKey' => $this->apiKey,
            'limit' => 5,
        ];

        $response = Http::withoutVerifying()->get("{$this->baseUrl}/geocode/autocomplete", $query);

        return $response->json();
    }

    /**
     * Geocode an exact address to get coordinates.
     */
    public function geocode(string $street, string $number, string $neighborhood, string $postcode, string $city, string $state)
    {
        $query = [
            'street' => trim("$street $number"),
            'postcode' => $postcode,
            'city' => $city,
            'state' => $state,
            'country' => 'Mexico',
            'apiKey' => $this->apiKey,
            'limit' => 1,
        ];

        $response = Http::withoutVerifying()->get("{$this->baseUrl}/geocode/search", $query);

        return $response->json();
    }
}
