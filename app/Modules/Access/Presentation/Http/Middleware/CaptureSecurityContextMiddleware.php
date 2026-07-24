<?php

namespace App\Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CaptureSecurityContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->header('X-Correlation-Id');
        if (! is_string($correlationId) || ! Str::isUuid($correlationId)) {
            $correlationId = (string) Str::uuid();
        }
        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('security_signals', [
            'ip_address' => $request->ip(),
            'network' => $this->network($request->ip()),
            'coarse_location' => $this->coarseLocation($request),
            'application' => $request->header('X-Application-Id'),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            'device_id' => $request->cookie('mv_device'),
        ]);

        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }

    private function network(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return implode('.', array_slice($parts, 0, 3)).'.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 4)).'::/64';
        }

        return null;
    }

    private function coarseLocation(Request $request): ?string
    {
        $country = $request->header('X-Geo-Country');
        $region = $request->header('X-Geo-Region');

        return is_string($country) && $country !== ''
            ? mb_substr($country.($region ? '-'.$region : ''), 0, 64)
            : null;
    }
}
