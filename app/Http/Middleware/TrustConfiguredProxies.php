<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Symfony\Component\HttpFoundation\Request;

final class TrustConfiguredProxies extends TrustProxies
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    protected function proxies(): array|string|null
    {
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('production.trusted_proxies', ''))
        )));

        return $proxies === [] ? null : $proxies;
    }
}
