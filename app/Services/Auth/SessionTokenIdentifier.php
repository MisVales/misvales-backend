<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class SessionTokenIdentifier
{
    public function current(Request $request): ?string
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            return (string) $accessToken->getRawOriginal('token');
        }

        return $this->fromPlainTextToken($request->bearerToken());
    }

    public function legacy(Request $request): ?string
    {
        $plainTextToken = $request->bearerToken();

        return is_string($plainTextToken) && $plainTextToken !== ''
            ? hash('sha256', $plainTextToken)
            : null;
    }

    public function fromPlainTextToken(?string $plainTextToken): ?string
    {
        if (! is_string($plainTextToken) || ! str_contains($plainTextToken, '|')) {
            return null;
        }

        [$id, $secret] = explode('|', $plainTextToken, 2);
        if ($id === '' || ! ctype_digit($id) || $secret === '') {
            return null;
        }

        return hash('sha256', $secret);
    }
}
