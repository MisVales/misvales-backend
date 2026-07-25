<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources\Concerns;

use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Client\Persistence\Models\ClientAddress;
use Illuminate\Support\Carbon;

/** Utilidades de presentación que nunca exponen cifrado ni HMAC. */
trait FormatsProtectedClientData
{
    protected function protector(): SensitiveDataProtector
    {
        return app(SensitiveDataProtector::class);
    }

    protected function mask(string $last4, int $visibleLength = 18): string
    {
        return str_repeat('*', max($visibleLength - 4, 4)).$last4;
    }

    /** @return array<string, string|null>|null */
    protected function address(?ClientAddress $address): ?array
    {
        if ($address === null) {
            return null;
        }
        $protector = $this->protector();

        return [
            'street' => $protector->decrypt($address->street_ciphertext),
            'exterior_number' => $protector->decrypt($address->exterior_number_ciphertext),
            'interior_number' => $address->interior_number_ciphertext === null
                ? null
                : $protector->decrypt($address->interior_number_ciphertext),
            'neighborhood' => $protector->decrypt($address->neighborhood_ciphertext),
            'postal_code' => $protector->decrypt($address->postal_code_ciphertext),
            'municipality' => $protector->decrypt($address->municipality_ciphertext),
            'city' => $protector->decrypt($address->city_ciphertext),
            'state' => $protector->decrypt($address->state_ciphertext),
        ];
    }

    protected function displayDate(mixed $date): ?string
    {
        if (! $date instanceof \DateTimeInterface) {
            return null;
        }

        return Carbon::instance($date)
            ->timezone((string) config('app.display_timezone', 'America/Monterrey'))
            ->toIso8601String();
    }
}
