<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class AuthConfigurationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'turnstile' => [
                'enabled' => (bool) config('services.turnstile.enabled', false),
                'site_key' => (string) config('services.turnstile.site_key', ''),
            ],
            'diagnostics' => [
                'debug' => (bool) config('app.debug'),
            ],
        ]);
    }
}
